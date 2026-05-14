<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Integration;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\Definition\MinimumStringLengthDefinition;
use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DependencyInjection\Compiler\RuleDefinitionPass;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class RuleDefinitionPassTest extends TestCase
{
    public function test_pass_collects_tagged_services_into_registry(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RuleRegistry::class, new Definition(RuleRegistry::class))->setPublic(true);
        $container->setDefinition(NotEmptyDefinition::class, new Definition(NotEmptyDefinition::class))
            ->addTag(RuleDefinitionPass::TAG, ['key' => 'Not Empty'])
            ->setPublic(true);
        $container->setDefinition(MinimumStringLengthDefinition::class, new Definition(MinimumStringLengthDefinition::class))
            ->addTag(RuleDefinitionPass::TAG, ['key' => 'Minimum String Length'])
            ->setPublic(true);

        $container->addCompilerPass(new RuleDefinitionPass());
        $container->compile();

        /** @var RuleRegistry $registry */
        $registry = $container->get(RuleRegistry::class);

        self::assertInstanceOf(NotEmptyDefinition::class, $registry->get('Not Empty'));
        self::assertInstanceOf(MinimumStringLengthDefinition::class, $registry->get('Minimum String Length'));
        self::assertInstanceOf(NotEmptyDefinition::class, $registry->get(NotEmptyDefinition::class));
        self::assertSame(
            ['Not Empty', 'Minimum String Length'],
            array_keys($registry->all()),
        );
    }

    public function test_missing_key_attribute_throws_at_compile_time(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RuleRegistry::class, new Definition(RuleRegistry::class))->setPublic(true);
        $container->setDefinition('app.unkeyed_rule', new Definition(FakeKernelFreeRule::class))
            ->addTag(RuleDefinitionPass::TAG)
            ->setPublic(true);

        $container->addCompilerPass(new RuleDefinitionPass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('app.unkeyed_rule');
        $this->expectExceptionMessage(RuleDefinitionPass::TAG);

        $container->compile();
    }

    public function test_duplicate_key_collision_throws(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RuleRegistry::class, new Definition(RuleRegistry::class))->setPublic(true);
        $container->setDefinition('app.rule_a', new Definition(FakeKernelFreeRule::class))
            ->addTag(RuleDefinitionPass::TAG, ['key' => 'Collision'])
            ->setPublic(true);
        $container->setDefinition('app.rule_b', new Definition(FakeKernelFreeRule::class))
            ->addTag(RuleDefinitionPass::TAG, ['key' => 'Collision'])
            ->setPublic(true);

        $container->addCompilerPass(new RuleDefinitionPass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Collision');
        $this->expectExceptionMessage('app.rule_a');
        $this->expectExceptionMessage('app.rule_b');

        $container->compile();
    }

    public function test_empty_string_key_attribute_throws_at_compile_time(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RuleRegistry::class, new Definition(RuleRegistry::class))->setPublic(true);
        $container->setDefinition('app.empty_key_rule', new Definition(FakeKernelFreeRule::class))
            ->addTag(RuleDefinitionPass::TAG, ['key' => ''])
            ->setPublic(true);

        $container->addCompilerPass(new RuleDefinitionPass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('app.empty_key_rule');
        $this->expectExceptionMessage(RuleDefinitionPass::TAG);

        $container->compile();
    }

    public function test_no_registry_definition_is_a_noop(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.rule', new Definition(FakeKernelFreeRule::class))
            ->addTag(RuleDefinitionPass::TAG, ['key' => 'Whatever'])
            ->setPublic(true);

        $container->addCompilerPass(new RuleDefinitionPass());

        $container->compile();

        self::assertFalse($container->has(RuleRegistry::class));
    }
}

final class FakeKernelFreeRule implements DefinitionInterface
{
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        return true;
    }

    public function getNecessaryParameterCount(): int
    {
        return 0;
    }
}
