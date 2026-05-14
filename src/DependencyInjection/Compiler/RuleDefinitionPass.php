<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\DependencyInjection\Compiler;

use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class RuleDefinitionPass implements CompilerPassInterface
{
    public const TAG = 'data_quality.rule';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(RuleRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(RuleRegistry::class);
        $taggedServices = $container->findTaggedServiceIds(self::TAG);

        $seen = [];
        foreach ($taggedServices as $serviceId => $tagAttributes) {
            foreach ($tagAttributes as $attributes) {
                if (!isset($attributes['key']) || $attributes['key'] === '') {
                    throw new LogicException(sprintf(
                        'Service "%s" is tagged "%s" but is missing the required "key" attribute.',
                        $serviceId,
                        self::TAG,
                    ));
                }

                $key = (string) $attributes['key'];
                if (isset($seen[$key])) {
                    throw new LogicException(sprintf(
                        'Duplicate "%s" key "%s" declared by services "%s" and "%s".',
                        self::TAG,
                        $key,
                        $seen[$key],
                        $serviceId,
                    ));
                }
                $seen[$key] = $serviceId;

                $registry->addMethodCall('register', [$key, new Reference($serviceId)]);
            }
        }
    }
}
