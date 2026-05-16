<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Translation-aware rule that catches "stealth-English": every
 * scored target language must carry a string distinct from the
 * source-language value once trivial whitespace is trimmed.
 *
 * `$parameters[0]` optionally overrides the source language (rarely
 * used — defaults to `RuleContext::getSourceLanguage()`).
 *
 * Skip semantics:
 * - Source language itself is never compared against itself.
 * - Empty target values are skipped (a missing translation is the
 *   `LocalizedFillRatio` rule's concern, not this rule's).
 * - Empty source value: no baseline to compare against, returns
 *   `true` and defers to the gate or to `LocalizedFillRatio`.
 *
 * Container paths fan out via `RuleContext::getContainerLeaves()`,
 * comparing each leaf-language value against the source-language
 * leaf for the same container index — leaves are not memoised by
 * sub-index here because the field-path resolver already emits
 * one `ResolvedLeaf` per (item-index, language) tuple.
 *
 * For container paths the source language must be in the scored set
 * (because `getContainerLeaves()` only iterates scored languages).
 * Misconfiguration throws `\LogicException` rather than silently
 * reporting "all distinct" with zero comparisons performed.
 */
final class DistinctFromSource extends DefinitionAbstract implements LocalizedAwareDefinition
{
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        $sourceLang = isset($parameters[0]) && $parameters[0] !== ''
            ? (string) $parameters[0]
            : $context->getSourceLanguage();

        $fieldName = $fieldDefinition->getName();
        $scored = $context->getScoredLanguages();

        if (PathSyntax::isContainer($fieldName)) {
            $scoredSet = array_flip($scored);
            if (!isset($scoredSet[$sourceLang])) {
                throw new \LogicException(sprintf(
                    'DistinctFromSource cannot compare container leaves: source language "%s" is not in the scored set [%s]. Add it to the config allow-list or remove the source-language override.',
                    $sourceLang,
                    implode(', ', $scored),
                ));
            }
            $sourceByLeaf = [];
            $containerLeaves = $context->getContainerLeaves($fieldName);
            foreach ($containerLeaves as $leaf) {
                if ($leaf->language === $sourceLang && self::isFilled($leaf->value)) {
                    $sourceByLeaf[$leaf->leafPath] = $leaf->value;
                }
            }
            foreach ($containerLeaves as $leaf) {
                if (!isset($scoredSet[$leaf->language])) {
                    continue;
                }
                if ($leaf->language === $sourceLang) {
                    continue;
                }
                if (!self::isFilled($leaf->value)) {
                    continue;
                }
                $matchedSource = $sourceByLeaf[$leaf->leafPath] ?? null;
                if ($matchedSource === null || !self::isFilled($matchedSource)) {
                    continue;
                }
                if (self::normalise($leaf->value) === self::normalise($matchedSource)) {
                    return false;
                }
            }

            return true;
        }

        $sourceValue = $context->getValue($fieldName, $sourceLang);
        if (!self::isFilled($sourceValue)) {
            return true;
        }
        $sourceNormalised = self::normalise($sourceValue);

        $valuesByLang = $context->getValuesByLang($fieldName);
        foreach ($scored as $language) {
            if ($language === $sourceLang) {
                continue;
            }
            if (!array_key_exists($language, $valuesByLang)) {
                continue;
            }
            $value = $valuesByLang[$language];
            if (!self::isFilled($value)) {
                continue;
            }
            if (self::normalise($value) === $sourceNormalised) {
                return false;
            }
        }

        return true;
    }

    private static function normalise(mixed $value): string
    {
        return trim((string) $value);
    }
}
