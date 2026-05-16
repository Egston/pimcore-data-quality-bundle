<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Integration;

use Basilicom\DataQualityBundle\DependencyInjection\Compiler\LanguageFlagsProviderPass;
use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class LanguageFlagsProviderPassTest extends TestCase
{
    public function test_pass_collects_tagged_services_keyed_by_service_id(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LanguageFlagsProviderRegistry::class, new Definition(LanguageFlagsProviderRegistry::class))
            ->setPublic(true);
        $container->setDefinition('app.flags.brick_verified', new Definition(FakeFlagsProvider::class))
            ->addTag(LanguageFlagsProviderPass::TAG)
            ->setPublic(true);
        $container->setDefinition('app.flags.localized_field', new Definition(FakeFlagsProvider::class))
            ->addTag(LanguageFlagsProviderPass::TAG)
            ->setPublic(true);

        $container->addCompilerPass(new LanguageFlagsProviderPass());
        $container->compile();

        /** @var LanguageFlagsProviderRegistry $registry */
        $registry = $container->get(LanguageFlagsProviderRegistry::class);

        self::assertTrue($registry->has('app.flags.brick_verified'));
        self::assertTrue($registry->has('app.flags.localized_field'));
        self::assertSame(
            ['app.flags.brick_verified', 'app.flags.localized_field'],
            array_keys($registry->all()),
        );
    }

    public function test_tag_without_key_attribute_is_accepted(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LanguageFlagsProviderRegistry::class, new Definition(LanguageFlagsProviderRegistry::class))
            ->setPublic(true);
        $container->setDefinition('app.flags.unattributed', new Definition(FakeFlagsProvider::class))
            ->addTag(LanguageFlagsProviderPass::TAG)
            ->setPublic(true);

        $container->addCompilerPass(new LanguageFlagsProviderPass());
        $container->compile();

        /** @var LanguageFlagsProviderRegistry $registry */
        $registry = $container->get(LanguageFlagsProviderRegistry::class);

        self::assertTrue($registry->has('app.flags.unattributed'));
    }

    public function test_no_registry_definition_is_a_noop(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.flags.orphan', new Definition(FakeFlagsProvider::class))
            ->addTag(LanguageFlagsProviderPass::TAG)
            ->setPublic(true);

        $container->addCompilerPass(new LanguageFlagsProviderPass());
        $container->compile();

        self::assertFalse($container->has(LanguageFlagsProviderRegistry::class));
    }
}

final class FakeFlagsProvider implements LanguageFlagsProvider
{
    public function getName(): string
    {
        return 'fake';
    }

    public function getFlags(Concrete $object): array
    {
        return [];
    }

    public function supportedClassIds(): array
    {
        return [];
    }
}
