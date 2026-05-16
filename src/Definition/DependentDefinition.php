<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Marker interface for rules that read sibling DataQualityConfig output
 * columns on the same DataObject — a "headline" rule deriving from per-axis
 * configs without invoking the full evaluator again.
 *
 * `dependsOnColumns()` is consumed by `DependencyResolver` to topologically
 * sort the set of configs evaluated in one run, so a producer config writes
 * its score column before any consumer reads it. The orchestrator runs the
 * sort at the parent process of `UpdateAllDataQualityCommand` (before
 * child-process spawn) and in the in-process `ObjectPreSaveListener` loop;
 * a child process processing one config in isolation can't see siblings,
 * which is why the sort lives in the parent.
 *
 * `skipFromScore()` is queried after `validate()` runs. Returning `true`
 * marks the rule's row N/A for the object — composes with the existing
 * gate-driven N/A bookkeeping so an all-NULL-input blend writes SQL NULL
 * via `writeFieldDirect` rather than counting as a "zero" in the
 * numerator. The state lives on the rule instance and must be reset at
 * the top of every `validate()` call.
 */
interface DependentDefinition
{
    /**
     * @param array<int|string, mixed> $parameters parsed parameter map
     *                                             for the current config row
     *
     * @return array<int, array{class: string, column: string}>
     *   `class` is the Pimcore class ID (string, matches
     *   `DataQualityConfig::getDataQualityClass()`); `column` is the
     *   field name (matches `DataQualityConfig::getDataQualityField()`).
     *
     * @throws DefinitionException
     */
    public function dependsOnColumns(array $parameters, DataQualityConfig $config): array;

    /**
     * Whether the most recent `validate()` should be treated as N/A
     * (`applied=false`) rather than as a fail. The orchestrator queries
     * this after every `validate()` call on a `DependentDefinition`
     * rule; the rule resets the answer at the top of each `validate()`.
     */
    public function skipFromScore(): bool;
}
