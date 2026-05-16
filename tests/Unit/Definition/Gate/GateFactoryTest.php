<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\AllLangsFilledGate;
use Basilicom\DataQualityBundle\Definition\Gate\AlwaysApplyGate;
use Basilicom\DataQualityBundle\Definition\Gate\AnyLangFilledGate;
use Basilicom\DataQualityBundle\Definition\Gate\ExpressionGate;
use Basilicom\DataQualityBundle\Definition\Gate\PublishedGate;
use Basilicom\DataQualityBundle\Definition\Gate\SourceFilledGate;
use Basilicom\DataQualityBundle\Definition\GateFactory;
use Basilicom\DataQualityBundle\Definition\InvalidGateException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class GateFactoryTest extends TestCase
{
    public function test_empty_string_returns_always_apply_gate(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        self::assertInstanceOf(AlwaysApplyGate::class, $factory->fromString(''));
    }

    public function test_null_returns_always_apply_gate(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        self::assertInstanceOf(AlwaysApplyGate::class, $factory->fromString(null));
    }

    public function test_whitespace_only_returns_always_apply_gate(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        self::assertInstanceOf(AlwaysApplyGate::class, $factory->fromString('   '));
    }

    /**
     * @dataProvider keywordProvider
     */
    public function test_known_keyword_resolves_to_its_gate(string $keyword, string $expectedClass): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        self::assertInstanceOf($expectedClass, $factory->fromString($keyword));
    }

    /**
     * @return iterable<array{string, class-string}>
     */
    public static function keywordProvider(): iterable
    {
        yield 'source_filled' => ['source_filled', SourceFilledGate::class];
        yield 'any_lang_filled' => ['any_lang_filled', AnyLangFilledGate::class];
        yield 'all_langs_filled' => ['all_langs_filled', AllLangsFilledGate::class];
        yield 'published' => ['published', PublishedGate::class];
    }

    public function test_unknown_keyword_throws_with_keyword_in_message(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        $this->expectException(InvalidGateException::class);
        $this->expectExceptionMessage('foobar');

        $factory->fromString('foobar');
    }

    public function test_expr_prefix_parses_valid_expression(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        $gate = $factory->fromString('expr:object.isPublished()');

        self::assertInstanceOf(ExpressionGate::class, $gate);
        self::assertSame('object.isPublished()', $gate->getSource());
    }

    public function test_expr_with_syntax_error_throws_invalid_gate_exception(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        try {
            $factory->fromString('expr:foo bar baz');
            self::fail('Expected InvalidGateException');
        } catch (InvalidGateException $e) {
            self::assertStringContainsString('foo bar baz', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'underlying SyntaxError must be preserved as $previous');
        }
    }

    public function test_expr_referencing_lang_variable_throws_at_parse_time(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        $this->expectException(InvalidGateException::class);

        $factory->fromString("expr:lang == 'en'");
    }

    public function test_invalid_gate_exception_preserves_gate_source(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        try {
            $factory->fromString('not_a_real_keyword');
            self::fail('Expected InvalidGateException');
        } catch (InvalidGateException $e) {
            self::assertSame('not_a_real_keyword', $e->getGateSource());
        }
    }

    public function test_expr_prefix_takes_precedence_over_keyword_lookup(): void
    {
        $factory = new GateFactory(new ExpressionLanguage());

        // `source_filled` is a valid keyword; wrapping it in `expr:` must parse
        // it as an expression (where `source_filled` is not a bound variable),
        // not silently resolve to SourceFilledGate.
        $this->expectException(InvalidGateException::class);

        $factory->fromString('expr:source_filled');
    }
}
