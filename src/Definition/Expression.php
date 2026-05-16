<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Basilicom\DataQualityBundle\Service\ExpressionEvaluator;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Expression-driven translation-aware rule.
 *
 * Parameters: `expression` (string, required) — Symfony ExpressionLanguage
 * body; `provider_service_id` (string, optional) — service ID of a
 * registered `LanguageFlagsProvider` (absent ⇒ `flagsByLang` is `null`).
 *
 * The two path modes bind different variable sets; see
 * `ExpressionEvaluator::VARIABLE_NAMES_*`. Runtime errors fail-CLOSED
 * (rules) rather than fail-OPEN (gates) because a rule's numerator
 * contribution is meaningful.
 *
 * `RuleContext::$flagsProvider` is intentionally NOT consumed here — the
 * slot exists for a different call site and is out of scope for this rule.
 */
final class Expression extends DefinitionAbstract implements LocalizedAwareDefinition
{
    public function __construct(
        private readonly ExpressionEvaluator $evaluator,
        private readonly LanguageFlagsProviderRegistry $registry,
    ) {}

    /**
     * @throws DefinitionException
     */
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        if (!isset($parameters['expression']) || !\is_string($parameters['expression']) || $parameters['expression'] === '') {
            throw new DefinitionException(
                'Expression rule requires the "expression" parameter (non-empty string).',
            );
        }
        $source = $parameters['expression'];

        $flagsByLang = $this->resolveFlagsByLang($parameters, $context);

        $fieldName = $fieldDefinition->getName();
        if (PathSyntax::isContainer($fieldName)) {
            return $this->evaluateContainer($source, $fieldName, $flagsByLang, $context);
        }

        return $this->evaluateNonContainer($source, $fieldName, $flagsByLang, $context);
    }

    /**
     * @param array<string, mixed>      $parameters
     *
     * @return array<string, bool>|null
     *
     * @throws DefinitionException
     */
    private function resolveFlagsByLang(array $parameters, RuleContext $context): ?array
    {
        if (!isset($parameters['provider_service_id']) || $parameters['provider_service_id'] === '') {
            return null;
        }
        $serviceId = (string) $parameters['provider_service_id'];

        $provider = $this->registry->get($serviceId);
        if ($provider === null) {
            throw new DefinitionException(sprintf(
                'Expression rule provider "%s" is not registered. Tag a service '
                . '"data_quality.language_flags_provider" with that ID.',
                $serviceId,
            ));
        }

        try {
            return $provider->getFlags($context->getObject());
        } catch (\Throwable $e) {
            \Pimcore\Logger::warning(sprintf(
                'DataQualityBundle: Expression provider failure providerServiceId=%s providerClass=%s oo_id=%d: %s (%s)',
                $serviceId,
                $provider::class,
                (int) $context->getObject()->getId(),
                $e->getMessage(),
                $e::class,
            ));
            throw $e;
        }
    }

    /**
     * @param array<string, bool>|null $flagsByLang
     */
    private function evaluateNonContainer(
        string $source,
        string $fieldName,
        ?array $flagsByLang,
        RuleContext $context,
    ): bool {
        $scored = $context->getScoredLanguages();
        if ($scored === []) {
            return true;
        }

        $valuesByLang = $context->getValuesByLang($fieldName);
        $shared = [
            'object' => $context->getObject(),
            'valuesByLang' => $valuesByLang,
            'flagsByLang' => $flagsByLang,
            'sourceLanguage' => $context->getSourceLanguage(),
        ];

        foreach ($scored as $language) {
            $variables = $shared + [
                'lang' => $language,
                'value' => $valuesByLang[$language] ?? null,
            ];

            try {
                $result = $this->evaluator->evaluate(
                    $source,
                    $variables,
                    ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
                );
            } catch (DefinitionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->logRuntimeFailure($source, $context, $e);

                return false;
            }

            if (!(bool) $result) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, bool>|null $flagsByLang
     */
    private function evaluateContainer(
        string $source,
        string $containerPath,
        ?array $flagsByLang,
        RuleContext $context,
    ): bool {
        $variables = [
            'object' => $context->getObject(),
            'containerLeaves' => $context->getContainerLeaves($containerPath),
            'flagsByLang' => $flagsByLang,
            'sourceLanguage' => $context->getSourceLanguage(),
        ];

        try {
            $result = $this->evaluator->evaluate(
                $source,
                $variables,
                ExpressionEvaluator::VARIABLE_NAMES_CONTAINER,
            );
        } catch (DefinitionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logRuntimeFailure($source, $context, $e);

            return false;
        }

        return (bool) $result;
    }

    private function logRuntimeFailure(string $source, RuleContext $context, \Throwable $e): void
    {
        $config = $context->getConfig();
        \Pimcore\Logger::warning(sprintf(
            'DataQualityBundle: Expression rule runtime failure for configId=%s expression="%s" oo_id=%d: %s (%s)',
            (string) ($config->getId() ?? 'unsaved'),
            $source,
            (int) $context->getObject()->getId(),
            $e->getMessage(),
            $e::class,
        ));
    }
}
