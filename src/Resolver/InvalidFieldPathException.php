<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Resolver;

/**
 * Thrown by `FieldPathResolver::resolve()` (and surfaced indirectly via
 * `FieldDefinitionFactory::get()` on container/`[]` paths configured
 * against rules that do not implement `LocalizedAwareDefinition`) when a
 * configured field path cannot be walked against the live object.
 *
 * Message carries the failing path verbatim so admin-form save errors
 * point a curator at the exact config row to fix.
 */
final class InvalidFieldPathException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Invalid data-quality field path "%s": %s', $path, $reason), 0, $previous);
    }
}
