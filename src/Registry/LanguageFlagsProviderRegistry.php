<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Registry;

use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;

/**
 * Service-id-indexed registry of `LanguageFlagsProvider` instances.
 *
 * The rule persists the Symfony service ID in its `provider_service_id`
 * parameter; the registry round-trips it. Display names are read
 * through `LanguageFlagsProvider::getName()` for the admin dropdown.
 */
final class LanguageFlagsProviderRegistry
{
    /** @var array<string, LanguageFlagsProvider> */
    private array $byServiceId = [];

    public function register(string $serviceId, LanguageFlagsProvider $provider): void
    {
        $this->byServiceId[$serviceId] = $provider;
    }

    public function get(string $serviceId): ?LanguageFlagsProvider
    {
        return $this->byServiceId[$serviceId] ?? null;
    }

    public function has(string $serviceId): bool
    {
        return isset($this->byServiceId[$serviceId]);
    }

    /**
     * @return array<string, LanguageFlagsProvider>
     */
    public function all(): array
    {
        return $this->byServiceId;
    }
}
