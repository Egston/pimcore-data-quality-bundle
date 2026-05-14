<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Resolver;

/**
 * One leaf reached by `FieldPathResolver::resolve()`.
 *
 * `$leafPath` is the dotted path with `[]` segments expanded to concrete
 * indices (e.g. `sections[].title` resolves to `sections[0].title`,
 * `sections[1].title`, ...). Container-terminated paths expand to one
 * leaf per inner localized field per item.
 */
final class ResolvedLeaf
{
    public function __construct(
        public readonly string $leafPath,
        public readonly string $language,
        public readonly mixed $value,
    ) {
        \assert($leafPath !== '', 'ResolvedLeaf::$leafPath must not be empty');
        \assert($language !== '', 'ResolvedLeaf::$language must not be empty');
    }
}
