<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * `$parameters[0]` is the optional `min_ratio` (float 0..1). Default
 * `0.0` degenerates to "any content passes" — useful as a gate-style
 * row that contributes a pass weight to the numerator iff at least
 * the source language is filled (combine with a `source_filled` gate
 * for that pattern).
 *
 * Empty `getScoredLanguages()` returns `true` — there is no language
 * to score; the all-N/A handling is a config-level concern, not a
 * rule concern. Container paths with all leaves excluded from the
 * scored set likewise return `true` (zero denominator, same rationale).
 *
 * Container paths fan out via `RuleContext::getContainerLeaves()`,
 * each leaf treated as one (language, value) pair across all leaves.
 */
final class LocalizedFillRatio extends DefinitionAbstract implements LocalizedAwareDefinition
{
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        $minRatio = isset($parameters[0]) && $parameters[0] !== ''
            ? (float) $parameters[0]
            : 0.0;

        $scored = $context->getScoredLanguages();
        if ($scored === []) {
            return true;
        }

        $fieldName = $fieldDefinition->getName();

        if (self::isContainerPath($fieldName)) {
            $scoredSet = array_flip($scored);
            $total = 0;
            $filled = 0;
            foreach ($context->getContainerLeaves($fieldName) as $leaf) {
                if (!isset($scoredSet[$leaf->language])) {
                    continue;
                }
                $total++;
                if (self::isFilled($leaf->value)) {
                    $filled++;
                }
            }
            if ($total === 0) {
                return true;
            }

            return ($filled / $total) >= $minRatio;
        }

        $valuesByLang = $context->getValuesByLang($fieldName);
        $filled = 0;
        foreach ($scored as $language) {
            if (array_key_exists($language, $valuesByLang) && self::isFilled($valuesByLang[$language])) {
                $filled++;
            }
        }

        return ($filled / count($scored)) >= $minRatio;
    }

    private static function isContainerPath(string $fieldName): bool
    {
        return str_contains($fieldName, '[]') || str_contains($fieldName, '.');
    }
}
