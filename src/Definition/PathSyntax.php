<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

/**
 * Predicates over the field-path string syntax shared by every
 * translation-aware rule and by the field-definition factory.
 *
 * A "container path" fans out to multiple resolved leaves (either via
 * `[]` iteration or via dotted traversal into a fieldcollection /
 * objectbrick). Container-terminated paths like `sections###de` carry
 * no dot or bracket on the field-name half (the language is on the
 * right-hand side of `###`) and are NOT recognised here — the
 * `FieldDefinitionFactory` strips `###<lang>` before consulting this
 * predicate.
 */
final class PathSyntax
{
    private function __construct() {}

    public static function isContainer(string $field): bool
    {
        return str_contains($field, '[]') || str_contains($field, '.');
    }
}
