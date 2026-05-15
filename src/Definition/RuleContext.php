<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Resolver\ResolvedLeaf;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Per-recompute, per-`(object, config)` evaluation context handed to every
 * `DefinitionInterface::validate()` call.
 *
 * Memoisation caches live on the instance — instances are discarded
 * between objects so there is no cross-object leak. The bounded path set
 * (config rules × configured languages) keeps the per-instance cache
 * small; no eviction strategy is needed.
 *
 * A large class recompute walks the same field set across all configured
 * languages for every rule on every object — without memoisation the
 * resolver would re-enter the localized-fields container on every rule
 * call, which is the perf concern that justifies this surface area.
 */
final class RuleContext
{
    /** @var array<string, array<string, mixed>> */
    private array $valueCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $valuesByLangCache = [];

    /** @var array<string, ResolvedLeaf[]> */
    private array $containerCache = [];

    /** @var array<string, bool>|null */
    private ?array $flagsByLang = null;

    private bool $flagsProbed = false;

    public function __construct(
        private readonly Concrete $object,
        private readonly DataQualityConfig $config,
        private readonly FieldPathResolverInterface $resolver,
        private readonly ?LanguageFlagsProvider $flagsProvider,
        private readonly LanguageScope $languageScope,
    ) {
    }

    public function getObject(): Concrete
    {
        return $this->object;
    }

    public function getConfig(): DataQualityConfig
    {
        return $this->config;
    }

    public function getSourceLanguage(): string
    {
        return $this->languageScope->getSourceLanguage();
    }

    /**
     * Languages that count toward the score (the config's allow-list).
     *
     * @return string[]
     */
    public function getScoredLanguages(): array
    {
        return $this->languageScope->getScoredLanguages();
    }

    /**
     * All valid Pimcore languages (the superset used for source-language
     * reads even when the source is excluded from scoring).
     *
     * @return string[]
     */
    public function getAllLanguages(): array
    {
        return $this->languageScope->getAllLanguages();
    }

    /**
     * Single value at a resolved path in one language. Memoised per
     * `(path, language)`.
     */
    public function getValue(string $path, string $language): mixed
    {
        if (array_key_exists($path, $this->valueCache)
            && array_key_exists($language, $this->valueCache[$path])
        ) {
            return $this->valueCache[$path][$language];
        }

        $leaves = $this->resolver->resolve($this->object, $path, $language);
        if (count($leaves) > 1) {
            throw new \LogicException(sprintf(
                'RuleContext::getValue() resolved "%s" to %d leaves; use getContainerLeaves() for fan-out paths.',
                $path,
                count($leaves),
            ));
        }
        $value = $leaves === [] ? null : $leaves[0]->value;
        $this->valueCache[$path][$language] = $value;

        return $value;
    }

    /**
     * Map<language, value> at a path. Memoised by path.
     *
     * Iterates `getAllLanguages()` so callers can still read the source
     * language even when it is excluded from `getScoredLanguages()`.
     *
     * @return array<string, mixed>
     */
    public function getValuesByLang(string $path): array
    {
        if (array_key_exists($path, $this->valuesByLangCache)) {
            return $this->valuesByLangCache[$path];
        }

        $map = [];
        foreach ($this->getAllLanguages() as $language) {
            $map[$language] = $this->getValue($path, $language);
        }
        $this->valuesByLangCache[$path] = $map;

        return $map;
    }

    /**
     * All `ResolvedLeaf`s reachable under a container-terminated path,
     * iterating every configured language. Memoised by container path.
     *
     * @return ResolvedLeaf[]
     */
    public function getContainerLeaves(string $containerPath): array
    {
        if (array_key_exists($containerPath, $this->containerCache)) {
            return $this->containerCache[$containerPath];
        }

        $leaves = [];
        foreach ($this->getScoredLanguages() as $language) {
            foreach ($this->resolver->resolve($this->object, $containerPath, $language) as $leaf) {
                $leaves[] = $leaf;
            }
        }
        $this->containerCache[$containerPath] = $leaves;

        return $leaves;
    }

    /**
     * Per-language verification flags from the configured provider, or
     * `null` when no provider is attached. One provider call per context
     * lifetime; the result map is memoised including absent-language
     * keys (a key absent from the result is "unknown", distinct from
     * `false`).
     *
     * @return array<string, bool>|null
     */
    public function getFlagsByLang(): ?array
    {
        if (!$this->flagsProbed) {
            $this->flagsByLang = $this->flagsProvider?->getFlags($this->object);
            $this->flagsProbed = true;
        }

        return $this->flagsByLang;
    }
}
