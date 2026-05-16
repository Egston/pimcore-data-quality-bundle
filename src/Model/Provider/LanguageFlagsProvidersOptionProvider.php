<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Model\Provider;

use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;

/**
 * Read-only discovery panel for the admin: lists every registered
 * `LanguageFlagsProvider` by service ID / display name so curators
 * can find the right `provider_service_id` to wire into a rule.
 */
final class LanguageFlagsProvidersOptionProvider implements SelectOptionsProviderInterface
{
    public function __construct(private readonly LanguageFlagsProviderRegistry $registry) {}

    public function getOptions($context, $fieldDefinition): array
    {
        $options = [];
        foreach ($this->registry->all() as $serviceId => $provider) {
            $options[] = [
                'value' => $serviceId,
                'key'   => $provider->getName(),
            ];
        }

        return $options;
    }

    public function hasStaticOptions($context, $fieldDefinition): bool
    {
        return false;
    }

    public function getDefaultValue($context, $fieldDefinition): ?string
    {
        return $fieldDefinition->getDefaultValue();
    }
}
