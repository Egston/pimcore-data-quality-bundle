<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Provider;

use Pimcore\Model\DataObject\Concrete;

/**
 * Per-language boolean signals an object carries (e.g. "translation
 * verified" flags from a Pimcore brick, a localized boolean field, an
 * external classification store, a remote service).
 *
 * Languages absent from the returned map are "unknown" — distinct from
 * `false`. A flag absent for the source language (which a translator
 * does not verify against itself) lets `LanguageFlagRatio` ignore the
 * source language without coercing it into "always unverified" and
 * pulling every ratio down.
 *
 * Providers are value-oriented: given an object, return flags. No
 * mutation, no side effects. Recompute stays idempotent.
 */
interface LanguageFlagsProvider
{
    public function getName(): string;

    /**
     * Map<lang, bool>. Languages absent from the map are "unknown" —
     * distinct from `false`.
     *
     * @return array<string, bool>
     */
    public function getFlags(Concrete $object): array;

    /**
     * Class IDs this provider answers for. Empty = all classes.
     *
     * @return string[]
     */
    public function supportedClassIds(): array;
}
