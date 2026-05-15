<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

/**
 * Language axes for a single per-`(object, config)` recompute.
 *
 * - `source` is the default Pimcore language; rules that compare a
 *   non-source value against the source-language value read it through
 *   here even when the source is excluded from scoring.
 * - `scored` is the config's allow-list — the languages that count
 *   toward the percentage.
 * - `all` is the full set of valid Pimcore languages — the superset
 *   used for source-language reads.
 *
 * Invariants are enforced at construction so downstream code can rely
 * on them without re-checking. `Tool::getValidLanguages()` is kernel-
 * bound; resolve it in the kernel-aware composition root and pass the
 * resulting arrays in.
 */
final class LanguageScope
{
    /**
     * @throws \InvalidArgumentException when source/scored/all violate
     *                                   the documented invariants
     */
    public function __construct(
        private readonly string $source,
        private readonly array $scored,
        private readonly array $all,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('LanguageScope: source language must not be empty.');
        }
        if ($all === []) {
            throw new \InvalidArgumentException('LanguageScope: all-languages set must not be empty.');
        }
        if (!in_array($source, $all, true)) {
            throw new \InvalidArgumentException(sprintf(
                'LanguageScope: source language "%s" is not in the all-languages set [%s].',
                $source,
                implode(', ', $all),
            ));
        }
        foreach ($scored as $language) {
            if (!in_array($language, $all, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'LanguageScope: scored language "%s" is not in the all-languages set [%s].',
                    $language,
                    implode(', ', $all),
                ));
            }
        }
    }

    public function getSourceLanguage(): string
    {
        return $this->source;
    }

    /**
     * @return string[]
     */
    public function getScoredLanguages(): array
    {
        return $this->scored;
    }

    /**
     * @return string[]
     */
    public function getAllLanguages(): array
    {
        return $this->all;
    }
}
