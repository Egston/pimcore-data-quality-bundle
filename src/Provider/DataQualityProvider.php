<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Provider;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\Definition\GateFactory;
use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\Model\Listener\ObjectPreSaveListener;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\View\DataQualityFieldViewModel;
use Basilicom\DataQualityBundle\View\DataQualityGroupViewModel;
use Basilicom\DataQualityBundle\View\DataQualityViewModel;
use Pimcore\Db;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\Version as DataObjectVersion;
use Pimcore\Tool;

final class DataQualityProvider
{
    public function __construct(
        private readonly FieldDefinitionFactory $fieldDefinitionFactory,
        private readonly FieldPathResolverInterface $fieldPathResolver,
        private readonly ?GateFactory $gateFactory = null,
    ) {}

    private function setDataQualityPercent(
        AbstractObject $dataObject,
        array $groups,
        string $fieldName,
        bool $persist,
        bool $useFastPath = true
    ): ?int {
        $countTotal    = 0;
        $countComplete = 0;

        /** @var DataQualityGroupViewModel $group */
        foreach ($groups as $group) {
            foreach ($group->getFields() as $field) {
                if (!$field->isApplied()) {
                    continue;
                }
                $countTotal = $countTotal + (1 * $field->getWeight());
                if ($field->isValid()) {
                    $countComplete = $countComplete + (1 * $field->getWeight());
                }
            }
        }
        $value = $countTotal === 0 ? null : (int) \round(($countComplete / $countTotal) * 100);
        $cast  = $value === null ? null : (float) $value;

        $setter = 'set' . \ucfirst($fieldName);
        if (\method_exists(
            $dataObject,
            $setter
        )) {
            $dataObject->$setter($cast);

            if ($persist) {
                // Fast path skips InheritanceHelper::saveChildData; a leaf
                // is safe regardless of the class-level allowInherit flag.
                $needsFanOut = $dataObject->getClass()->getAllowInherit()
                    && $dataObject->getChildAmount() > 0;
                if ($useFastPath && !$needsFanOut) {
                    $this->writeFieldDirect($dataObject, $fieldName, $cast);
                } else {
                    ObjectPreSaveListener::withListenerDisabled(function () use ($dataObject) {
                        DataObjectVersion::disable();
                        try {
                            $dataObject->save();
                        } finally {
                            DataObjectVersion::enable();
                        }
                    });
                }
            }
        }

        return $value;
    }

    /**
     * Direct UPDATE on object_store_<cid> + object_query_<cid>, bypassing
     * save(). Does NOT bump modificationDate — a DQ recompute isn't a
     * content edit. Only safe when the object has no children
     * (saveChildData fan-out is skipped); caller must enforce that.
     */
    private function writeFieldDirect(AbstractObject $dataObject, string $fieldName, ?float $value): void
    {
        $classId = $dataObject->getClassId();
        $id      = (int) $dataObject->getId();
        if ($classId === '' || $id <= 0) {
            throw new DataQualityException(
                sprintf('Cannot write DQ field directly: object has invalid classId ("%s") or id (%d).', $classId, $id)
            );
        }

        $db        = Db::get();
        $storeTable = 'object_store_' . $classId;
        $queryTable = 'object_query_' . $classId;

        $db->transactional(function ($db) use ($storeTable, $queryTable, $fieldName, $value, $id): void {
            // MySQL reports rows-affected as CHANGED, not MATCHED — a
            // no-op UPDATE returns 0, indistinguishable from "row missing".
            $exists = (bool) $db->fetchOne(
                sprintf('SELECT 1 FROM %s WHERE oo_id = ?', $storeTable),
                [$id]
            );
            if (!$exists) {
                throw new DataQualityException(
                    sprintf('Direct DQ write target missing: no row in %s for oo_id=%d.', $storeTable, $id)
                );
            }

            $db->update($storeTable, [$fieldName => $value], ['oo_id' => $id]);
            // object_query is absent for variants — UPDATE matches 0 silently.
            $db->update($queryTable, [$fieldName => $value], ['oo_id' => $id]);
        });

        try {
            AbstractObject::clearDependentCacheByObjectId($id);
        } catch (\Throwable $e) {
            \Pimcore\Logger::warning(sprintf(
                'DataQualityBundle: cache invalidation failed for oo_id=%d: %s',
                $id,
                $e->getMessage()
            ));
            throw new DataQualityException(
                sprintf('Cache invalidation failed for oo_id=%d after DB write.', $id),
                0,
                $e
            );
        }
    }

    /**
     * @return DataQualityConfig[]
     */
    public function getDataQualityConfigs(?AbstractObject $dataObject): array
    {
        $dataQualityConfigList = new DataQualityConfig\Listing();

        $dataQualityConfigs = [];
        foreach ($dataQualityConfigList as $dataQualityConfig) {
            $dataQualityClass = $dataQualityConfig->getDataQualityClass();
            if ($dataObject && $dataObject->getClassId() === $dataQualityClass) {
                if ($dataQualityConfig->isPublished()) {
                    $dataQualityConfigs[$dataQualityConfig->getId()] = $dataQualityConfig;
                }
            }
        }

        return $dataQualityConfigs;
    }

    /**
     * @throws DefinitionException
     */
    public function calculateDataQuality(
        AbstractObject $dataObject,
        DataQualityConfig $dataQualityConfig,
        bool $persist,
        bool $useFastPath = true
    ): DataQualityViewModel {
        $dataQualityRules = $this->getDataQualityRules($dataQualityConfig);

        $context = $this->createRuleContext($dataObject, $dataQualityConfig);

        $dataQualityGroups = [];

        foreach ($dataQualityRules as $dataQualityRuleGroupName => $dataQualityRuleGroup) {
            $dataQualityFields = [];

            /** @var FieldDefinition $fieldDefinition */
            foreach ($dataQualityRuleGroup as $ruleIndex => $fieldDefinition) {
                $getter = 'get' . $fieldDefinition->getFieldName();
                if (!method_exists($dataObject, $getter)) {
                    continue;
                }

                $isLocalizedField = $this->isLocalizedField($dataObject, $fieldDefinition->getFieldName());
                $classFieldDefinition = $this->getClassFieldDefinition(
                    $dataObject,
                    $fieldDefinition->getFieldName(),
                );

                $apply = $this->evaluateGate(
                    $fieldDefinition,
                    $classFieldDefinition,
                    $context,
                    $dataQualityConfig,
                    $dataObject,
                    $ruleIndex,
                );

                [$valid, $validFields] = $this->dispatchRule(
                    $dataObject,
                    $getter,
                    $fieldDefinition,
                    $classFieldDefinition,
                    $isLocalizedField,
                    $apply,
                    $context,
                    $dataQualityConfig,
                    $ruleIndex,
                );

                [$valid, $validFields, $applied] = $this->applySkipFromScoreOverride(
                    $fieldDefinition,
                    $apply,
                    $valid,
                    $validFields,
                );

                $dataQualityFields[] = new DataQualityFieldViewModel(
                    $fieldDefinition->getTitle(),
                    $fieldDefinition->getWeight(),
                    $valid,
                    $fieldDefinition->getLanguage(),
                    $validFields,
                    $applied,
                );
            }

            $dataQualityGroups[] = new DataQualityGroupViewModel(
                $dataQualityRuleGroupName,
                $dataQualityFields
            );
        }

        $percent = $this->setDataQualityPercent(
            $dataObject,
            $dataQualityGroups,
            $dataQualityConfig->getDataQualityField(),
            $persist,
            $useFastPath
        );

        return new DataQualityViewModel(
            $dataQualityConfig->getDataQualityName(),
            $percent,
            $dataQualityGroups
        );
    }

    /**
     * When a `DependentDefinition` rule signals N/A via `skipFromScore()`,
     * demote `applied` to `false` so `setDataQualityPercent` writes SQL NULL
     * rather than counting the row as a numerator-zero fail.
     *
     * @param array<string, bool> $validFields
     *
     * @return array{0: bool, 1: array<string, bool>, 2: bool}
     */
    private function applySkipFromScoreOverride(
        FieldDefinition $fieldDefinition,
        bool $apply,
        bool $valid,
        array $validFields,
    ): array {
        $rule = $fieldDefinition->getConditionClass();
        if ($apply && $rule instanceof DependentDefinition && $rule->skipFromScore()) {
            return [true, [], false];
        }

        return [$valid, $validFields, $apply];
    }

    /**
     * Resolve the rule's gate and ask it whether the rule applies.
     * Catches `\Throwable` (not just `\Exception`) so OOM or TypeError
     * from a broken `expr:` body still produces a logged N/A row rather
     * than zeroing the whole config. Save-time validation is the loud
     * arm; this path is intentionally fail-open even for engine errors.
     */
    private function evaluateGate(
        FieldDefinition $fieldDefinition,
        Data $classFieldDefinition,
        RuleContext $context,
        DataQualityConfig $dataQualityConfig,
        AbstractObject $dataObject,
        int $ruleIndex,
    ): bool {
        if ($this->gateFactory === null) {
            return true;
        }

        $gateSource = $fieldDefinition->getGate();
        if ($gateSource === null || $gateSource === '') {
            return true;
        }

        try {
            $gate = $this->gateFactory->fromString($gateSource);

            return $gate->evaluate($context, $classFieldDefinition);
        } catch (\Throwable $e) {
            \Pimcore\Logger::warning(sprintf(
                'DataQualityBundle: gate evaluation failed for configId=%s config="%s" oo_id=%d ruleIndex=%d rule "%s" gate "%s": %s (%s)',
                (string) ($dataQualityConfig->getId() ?? 'unsaved'),
                (string) ($dataQualityConfig->getDataQualityName() ?? ''),
                (int) $dataObject->getId(),
                $ruleIndex,
                $fieldDefinition->getFieldName(),
                (string) $gateSource,
                $e->getMessage(),
                $e::class,
            ));

            return false;
        }
    }

    /**
     * Route a single (field, rule) pair through the validator family.
     * `LocalizedAwareDefinition`-marked rules see the full scored set
     * in one call and manage their own per-language sweep through the
     * context — non-marker rules continue iterating once per language
     * via `validateLanguages()`. Object-brick fields take the per-brick
     * fan-out path regardless of marker status.
     *
     * @return array{0: bool, 1: array<string, bool>}
     */
    private function dispatchRule(
        AbstractObject $dataObject,
        string $getter,
        FieldDefinition $fieldDefinition,
        Data $classFieldDefinition,
        bool $isLocalizedField,
        bool $apply,
        RuleContext $context,
        DataQualityConfig $config,
        int $ruleIndex,
    ): array {
        if (!$apply) {
            return [true, []];
        }

        if ($this->isObjectBricks($classFieldDefinition)) {
            return $this->validateObjectBricks($dataObject, $getter, $fieldDefinition, $context);
        }

        if ($isLocalizedField && $fieldDefinition->getConditionClass() instanceof LocalizedAwareDefinition) {
            try {
                $valid = $fieldDefinition->getConditionClass()->validate(
                    null,
                    $classFieldDefinition,
                    $fieldDefinition->getParameters(),
                    $context,
                );
            } catch (\Throwable $e) {
                \Pimcore\Logger::warning(sprintf(
                    'DataQualityBundle: localized-aware rule "%s" (config #%s "%s" rule[%d]) threw for field "%s" on oo_id=%d: %s (%s)',
                    $fieldDefinition->getConditionClass()::class,
                    (string) ($config->getId() ?? 'unsaved'),
                    (string) ($config->getDataQualityName() ?? ''),
                    $ruleIndex,
                    $fieldDefinition->getFieldName(),
                    (int) $context->getObject()->getId(),
                    $e->getMessage(),
                    $e::class,
                ));

                return [false, []];
            }

            return [$valid, []];
        }

        if ($isLocalizedField) {
            return $this->validateLanguages(
                $dataObject,
                $getter,
                $fieldDefinition,
                $classFieldDefinition,
                $context,
            );
        }

        $value = $dataObject->$getter();
        $valid = $fieldDefinition->getConditionClass()->validate(
            $value,
            $classFieldDefinition,
            $fieldDefinition->getParameters(),
            $context,
        );

        return [$valid, []];
    }

    private function getDataQualityRules(DataQualityConfig $dataQualityConfig): array
    {
        $fieldCollection = $dataQualityConfig->getDataQualityRules();
        $items           = $fieldCollection->getItems();

        $rules = [];

        /** @var DataQualityFieldDefinition $item */
        foreach ($items as $item) {
            $group           = empty($item->getGroup()) ? FieldDefinitionFactory::DEFAULT_GROUP : $item->getGroup();
            $rules[$group][] = $this->fieldDefinitionFactory->get($item);
        }

        return $rules;
    }

    private function getClassFieldDefinition(AbstractObject $dataObject, string $fieldName): Data
    {
        $classDefinition = $dataObject->getClass();
        $classFieldDefinition = $classDefinition->getFieldDefinition($fieldName);

        return $classFieldDefinition;
    }

    private function isLocalizedField(AbstractObject $dataObject, string $fieldName): bool
    {
        $classDefinition = $dataObject->getClass();
        $fieldDefinition = $classDefinition->getFieldDefinition($fieldName);
        $isLocalizedField = false;

        // Loop through fields to find localizedfields container
        foreach ($classDefinition->getFieldDefinitions() as $field) {
            if ($field instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields) {
                $localizedFields = $field->getFieldDefinitions();
                if (array_key_exists($fieldName, $localizedFields)) {
                    $isLocalizedField = true;
                    break;
                }
            }
        }

        return $isLocalizedField;
    }

    private function isObjectBricks(Data $fieldDefinition): bool
    {
        return $fieldDefinition->getFieldtype() === 'objectbricks';
    }

    private function validateObjectBricks(
        AbstractObject $dataObject,
        string $getter,
        FieldDefinition $fieldDefinition,
        RuleContext $context
    ): array {
        $valid = true;
        $validFields = [];
        /** @var Objectbrick $brickContainer */
        $brickContainer = $dataObject->$getter();
        foreach ($brickContainer->getItems() as $brickItem) {
            $brickFieldDefinitions = $brickItem->getDefinition()->getFieldDefinitions();
            foreach ($brickFieldDefinitions as $brickField => $brickFieldValue) {
                $validFields[$brickField] = $fieldDefinition->getConditionClass()->validate(
                    $brickItem->get($brickField),
                    $brickFieldValue,
                    $fieldDefinition->getParameters(),
                    $context
                );

                $valid = $valid && $validFields[$brickField];
            }
        }

        return [
            $valid,
            $validFields,
        ];
    }

    private function validateLanguages(
        AbstractObject $dataObject,
        string $getter,
        FieldDefinition $fieldDefinition,
        Data $classFieldDefinition,
        RuleContext $context
    ): array {
        $languages = $context->getScoredLanguages();
        $languageValidity = [];

        $fieldLanguage = $fieldDefinition->getLanguage();
        if (!empty($fieldLanguage) && Tool::isValidLanguage($fieldLanguage)) {
            $value = $dataObject->$getter($fieldLanguage);
            $valid = $fieldDefinition->getConditionClass()->validate(
                $value,
                $classFieldDefinition,
                $fieldDefinition->getParameters(),
                $context
            );
        } else {
            $valid = true;
            foreach ($languages as $language) {
                $value                        = $dataObject->$getter($language);
                $languageValidity[$language]  = $fieldDefinition->getConditionClass()->validate(
                    $value,
                    $classFieldDefinition,
                    $fieldDefinition->getParameters(),
                    $context
                );

                $valid = $valid && $languageValidity[$language];
            }
        }

        return [
            $valid,
            $languageValidity,
        ];
    }

    private function createRuleContext(
        AbstractObject $dataObject,
        DataQualityConfig $dataQualityConfig
    ): RuleContext {
        $sourceLanguage = Tool::getDefaultLanguage();
        if ($sourceLanguage === null) {
            throw new DataQualityException('Cannot evaluate data-quality rules: no default language configured in Pimcore.');
        }

        $allLanguages = Tool::getValidLanguages();
        $scoredLanguages = $this->resolveScoredLanguages($dataQualityConfig, $allLanguages);

        return new RuleContext(
            $this->coerceToConcrete($dataObject),
            $dataQualityConfig,
            $this->fieldPathResolver,
            null,
            new LanguageScope($sourceLanguage, $scoredLanguages, $allLanguages),
        );
    }

    /**
     * Resolve the scoring allow-list for a config.
     *
     * An empty / null `dataQualityLanguages` is the user-facing intent
     * "score every configured language" — fall back to the full valid
     * set. A stale entry (locale removed from Pimcore after the config
     * was authored) throws so the operator sees the drift instead of
     * a silently-narrowed score.
     *
     * @param string[] $allLanguages
     *
     * @return string[]
     */
    private function resolveScoredLanguages(
        DataQualityConfig $dataQualityConfig,
        array $allLanguages
    ): array {
        $configured = $dataQualityConfig->getDataQualityLanguages();
        if ($configured === null || $configured === []) {
            return $allLanguages;
        }

        $stale = array_values(array_diff($configured, $allLanguages));
        if ($stale !== []) {
            throw new DataQualityException(sprintf(
                'DataQualityConfig "%s" references languages no longer valid in Pimcore: [%s]. Remove them from dataQualityLanguages or re-add the locales.',
                $dataQualityConfig->getDataQualityName() ?? (string) ($dataQualityConfig->getId() ?? 'unsaved-config'),
                implode(', ', $stale),
            ));
        }

        return array_values($configured);
    }

    private function coerceToConcrete(AbstractObject $dataObject): Concrete
    {
        if ($dataObject instanceof Concrete) {
            return $dataObject;
        }

        throw new DataQualityException(sprintf(
            'Cannot evaluate data-quality rules: expected a Concrete DataObject, got %s.',
            $dataObject::class,
        ));
    }
}
