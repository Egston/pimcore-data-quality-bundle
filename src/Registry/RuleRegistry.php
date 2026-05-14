<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Registry;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;

class RuleRegistry
{
    /** @var array<string, DefinitionInterface> */
    private array $byKey = [];

    public function register(string $key, DefinitionInterface $rule): void
    {
        $this->byKey[$key] = $rule;
    }

    /**
     * Resolves either a short display key ('Not Empty') or the legacy FQCN form
     * (Basilicom\DataQualityBundle\Definition\NotEmptyDefinition). Deployed configs
     * persisted FQCN strings in their `condition` varchar column prior to the registry.
     */
    public function get(string $keyOrFqcn): ?DefinitionInterface
    {
        if (isset($this->byKey[$keyOrFqcn])) {
            return $this->byKey[$keyOrFqcn];
        }

        foreach ($this->byKey as $rule) {
            if ($rule::class === $keyOrFqcn) {
                return $rule;
            }
        }

        return null;
    }

    public function has(string $keyOrFqcn): bool
    {
        return $this->get($keyOrFqcn) !== null;
    }

    /**
     * @return array<string, DefinitionInterface> indexed by display key
     */
    public function all(): array
    {
        return $this->byKey;
    }
}
