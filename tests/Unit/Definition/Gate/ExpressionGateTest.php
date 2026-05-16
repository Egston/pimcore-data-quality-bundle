<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\ExpressionGate;
use Basilicom\DataQualityBundle\Definition\GateFactory;
use Basilicom\DataQualityBundle\Definition\InvalidGateException;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class ExpressionGateTest extends TestCase
{
    public function test_object_variable_is_bound(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());
        $gate = $factory->fromString('expr:object.getValue() > 5');
        self::assertInstanceOf(ExpressionGate::class, $gate);

        $object = new class () extends Concrete {
            public function __construct()
            {
            }

            public function getValue(): int
            {
                return 10;
            }
        };
        $context = RuleContextBuilder::withValues(['en' => 'x'])
            ->object($object)
            ->build();

        self::assertTrue($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_values_by_lang_variable_is_bound(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());
        $gate = $factory->fromString("expr:valuesByLang['en'] == 'Foo'");

        $context = RuleContextBuilder::withValues(['en' => 'Foo', 'de' => 'Bar'])->build();

        self::assertTrue($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_values_by_lang_can_evaluate_false(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());
        $gate = $factory->fromString("expr:valuesByLang['en'] == 'Bar'");

        $context = RuleContextBuilder::withValues(['en' => 'Foo', 'de' => 'Bar'])->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_source_language_variable_is_bound(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());
        $gate = $factory->fromString("expr:sourceLanguage == 'en'");

        $context = RuleContextBuilder::withValues(['en' => 'Foo'])
            ->sourceLanguage('en')
            ->build();

        self::assertTrue($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_lang_variable_is_not_bound_and_rejected_at_parse_time(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        $this->expectException(InvalidGateException::class);

        $factory->fromString("expr:lang == 'en'");
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
