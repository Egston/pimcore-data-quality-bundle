<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\DependencyInjection\Compiler;

use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every service tagged `data_quality.language_flags_provider`
 * into `LanguageFlagsProviderRegistry`, keyed by the service's
 * container ID. The service ID is the persisted key; no separate
 * display-key attribute is required.
 */
final class LanguageFlagsProviderPass implements CompilerPassInterface
{
    public const TAG = 'data_quality.language_flags_provider';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(LanguageFlagsProviderRegistry::class)) {
            $tagged = $container->findTaggedServiceIds(self::TAG);
            if ($tagged !== []) {
                $container->log($this, sprintf(
                    'Services tagged "%s" will be dropped (%s is not registered): %s.',
                    self::TAG,
                    LanguageFlagsProviderRegistry::class,
                    implode(', ', array_keys($tagged)),
                ));
            }
            return;
        }

        $registry = $container->findDefinition(LanguageFlagsProviderRegistry::class);

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $serviceId) {
            $registry->addMethodCall('register', [$serviceId, new Reference($serviceId)]);
        }
    }
}
