<?php
declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Provider;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\View\DataQualityFieldViewModel;
use Basilicom\DataQualityBundle\View\DataQualityGroupViewModel;
use Basilicom\DataQualityBundle\View\DataQualityViewModel;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\Version as DataObjectVersion;
use Pimcore\Tool;

final class DataQualityProvider
{
    private FieldDefinitionFactory $fieldDefinitionFactory;

    public function __construct(FieldDefinitionFactory $fieldDefinitionFactory)
    {
        $this->fieldDefinitionFactory = $fieldDefinitionFactory;
    }

    private function setDataQualityPercent(
        AbstractObject $dataObject,
        array $groups,
        string $fieldName,
        bool $persist
    ): int {
        $countTotal    = 0;
        $countComplete = 0;

        /** @var DataQualityGroupViewModel $group */
        foreach ($groups as $group) {
            foreach ($group->getFields() as $field) {
                $countTotal = $countTotal + (1 * $field->getWeight());
                if ($field->isValid()) {
                    $countComplete = $countComplete + (1 * $field->getWeight());
                }
            }
        }
        $value = (int) \round(($countComplete / $countTotal) * 100);

        $setter = 'set' . \ucfirst($fieldName);
        if (\method_exists(
            $dataObject,
            $setter
        )) {
            $dataObject->$setter((float) $value);

            if ($persist) {
                DataObjectVersion::disable();
                $dataObject->save();
                DataObjectVersion::enable();
            }
        }

        return $value;
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
        bool $persist
    ): DataQualityViewModel {
        $dataQualityRules = $this->getDataQualityRules($dataQualityConfig);

        $dataQualityGroups = [];

        foreach ($dataQualityRules as $dataQualityRuleGroupName => $dataQualityRuleGroup) {
            $dataQualityFields = [];

            /** @var FieldDefinition $fieldDefinition */
            foreach ($dataQualityRuleGroup as $fieldDefinition) {
                $getter = 'get' . $fieldDefinition->getFieldName();
                if (!method_exists($dataObject, $getter)) {
                    continue;
                }

                $isLocalizedField = $this->isLocalizedField($dataObject, $fieldDefinition->getFieldName());
                $classFieldDefinition = $this->getClassFieldDefinition(
                    $dataObject,
                    $fieldDefinition->getFieldName(),
                );

                $validFields = [];
                if ($this->isObjectBricks($classFieldDefinition)) {
                    [$valid, $validFields] = $this->validateObjectBricks(
                        $dataObject,
                        $getter,
                        $fieldDefinition
                    );
                } elseif ($isLocalizedField) {
                    [$valid, $validFields] = $this->validateLanguages(
                        $dataObject,
                        $getter,
                        $fieldDefinition,
                        $classFieldDefinition
                    );
                } else {
                    $value = $dataObject->$getter();
                    $valid = $fieldDefinition->getConditionClass()->validate(
                        $value,
                        $classFieldDefinition,
                        $fieldDefinition->getParameters()
                    );
                }

                $dataQualityFields[] = new DataQualityFieldViewModel(
                    $fieldDefinition->getTitle(),
                    $fieldDefinition->getWeight(),
                    $valid,
                    $fieldDefinition->getLanguage(),
                    $validFields
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
            $persist
        );

        return new DataQualityViewModel(
            $dataQualityConfig->getDataQualityName(),
            $percent,
            $dataQualityGroups
        );
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
        FieldDefinition $fieldDefinition
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
                    $fieldDefinition->getParameters()
                );

                $valid = $valid && $validFields[$brickField];
            }
        }

        return [
            $valid,
            $validFields
        ];
    }

    private function validateLanguages(
        AbstractObject $dataObject,
        string $getter,
        FieldDefinition $fieldDefinition,
        Data $classFieldDefinition
    ): array {
        $languages = Tool::getValidLanguages();
        $validLanguages = [];

        $fieldLanguage = $fieldDefinition->getLanguage();
        if (!empty($fieldLanguage) && Tool::isValidLanguage($fieldLanguage)) {
            $value = $dataObject->$getter($fieldLanguage);
            $valid = $fieldDefinition->getConditionClass()->validate(
                $value,
                $classFieldDefinition,
                $fieldDefinition->getParameters()
            );
        } else {
            $valid = true;
            foreach ($languages as $language) {
                $value                     = $dataObject->$getter($language);
                $validLanguages[$language] = $fieldDefinition->getConditionClass()->validate(
                    $value,
                    $classFieldDefinition,
                    $fieldDefinition->getParameters()
                );

                $valid = $valid && $validLanguages[$language];
            }
        }

        return [
            $valid,
            $validLanguages
        ];
    }
}
