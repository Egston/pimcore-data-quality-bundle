<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Verification-flag rule: the configured `LanguageFlagsProvider`
 * yields a per-language boolean map (e.g. "translation verified"
 * flags from a brick or localized field); the rule passes when the
 * ratio of `true` flags over the scored-non-source language set
 * exceeds `min_ratio`.
 *
 * Named parameter contract (associative array):
 * - `provider_service_id` (string, required) — Symfony service ID of
 *   a registered `LanguageFlagsProvider`.
 * - `min_ratio` (float, optional, default 0.6).
 *
 * Source-language exclusion: EN (or whatever the config's source
 * language is) is excluded from BOTH numerator AND denominator
 * regardless of map contents — a translator does not verify against
 * their own language. The "scored MINUS source" set is the
 * discriminator.
 *
 * Unknown ≠ false: languages absent from the provider's result are
 * "unknown" and contribute to neither numerator nor denominator. A
 * provider returning the empty map (no brick attached, no localized
 * field populated) drives the denominator to zero, which the rule
 * treats as fail-open (returns `true`). The misconfiguration arm —
 * a missing service-id, an unregistered service-id, an out-of-range
 * ratio, a container path — fails LOUD via `DefinitionException`.
 */
final class LanguageFlagRatio extends DefinitionAbstract implements LocalizedAwareDefinition
{
    public function __construct(private readonly LanguageFlagsProviderRegistry $registry)
    {
    }

    /**
     * @throws DefinitionException
     */
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        if (!isset($parameters['provider_service_id']) || $parameters['provider_service_id'] === '') {
            throw new DefinitionException(
                'LanguageFlagRatio requires the "provider_service_id" parameter.',
            );
        }
        $serviceId = (string) $parameters['provider_service_id'];

        $minRatioRaw = $parameters['min_ratio'] ?? 0.6;
        if (!is_numeric($minRatioRaw)) {
            throw new DefinitionException(sprintf(
                'LanguageFlagRatio "min_ratio" must be numeric; got "%s".',
                get_debug_type($minRatioRaw),
            ));
        }
        $minRatio = (float) $minRatioRaw;
        if ($minRatio < 0.0 || $minRatio > 1.0) {
            throw new DefinitionException(sprintf(
                'LanguageFlagRatio "min_ratio" must be in [0.0, 1.0]; got %s.',
                (string) $minRatio,
            ));
        }

        $fieldName = $fieldDefinition->getName();
        if (PathSyntax::isContainer($fieldName)) {
            throw new DefinitionException(sprintf(
                'LanguageFlagRatio does not support container paths; got "%s". '
                . 'Configure the rule against a non-container field name.',
                $fieldName,
            ));
        }

        $provider = $this->registry->get($serviceId);
        if ($provider === null) {
            throw new DefinitionException(sprintf(
                'LanguageFlagRatio provider "%s" is not registered. Tag a service '
                . '"data_quality.language_flags_provider" with that ID.',
                $serviceId,
            ));
        }

        $flags = $provider->getFlags($context->getObject());
        $sourceLang = $context->getSourceLanguage();

        $denominator = 0;
        $numerator = 0;
        foreach ($context->getScoredLanguages() as $language) {
            if ($language === $sourceLang) {
                continue;
            }
            if (!array_key_exists($language, $flags)) {
                continue;
            }
            $denominator++;
            if ($flags[$language] === true) {
                $numerator++;
            }
        }

        if ($denominator === 0) {
            return true;
        }

        return ($numerator / $denominator) >= $minRatio;
    }
}
