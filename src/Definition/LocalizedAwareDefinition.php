<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

/**
 * Marker interface for rules that opt in to translation-aware path syntax.
 *
 * Container-terminated paths (e.g. `sections###de` — a fieldcollection /
 * objectbrick name with no inner field) and `[]`-iterating paths
 * (`sections[].title###All`) fan out to multiple localized leaves, and the
 * rule's `validate()` is expected to handle a per-language leaf set rather
 * than a single scalar value. Rules that do not implement this marker are
 * rejected at admin-form save time when configured against such a path —
 * the failure surfaces in `FieldDefinitionFactory::get()`.
 */
interface LocalizedAwareDefinition {}
