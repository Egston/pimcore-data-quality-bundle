<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Registry;

use Basilicom\DataQualityBundle\Definition\MinimumStringLengthDefinition;
use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    public function test_register_and_get_by_short_key(): void
    {
        $registry = new RuleRegistry();
        $rule = new NotEmptyDefinition();
        $registry->register('Not Empty', $rule);

        self::assertSame($rule, $registry->get('Not Empty'));
    }

    public function test_register_and_get_by_legacy_fqcn(): void
    {
        $registry = new RuleRegistry();
        $rule = new NotEmptyDefinition();
        $registry->register('Not Empty', $rule);

        self::assertSame($rule, $registry->get(NotEmptyDefinition::class));
    }

    public function test_has_returns_true_for_short_key_and_fqcn(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());

        self::assertTrue($registry->has('Not Empty'));
        self::assertTrue($registry->has(NotEmptyDefinition::class));
    }

    public function test_all_returns_key_indexed_map(): void
    {
        $registry = new RuleRegistry();
        $notEmpty = new NotEmptyDefinition();
        $minLen = new MinimumStringLengthDefinition();
        $registry->register('Not Empty', $notEmpty);
        $registry->register('Minimum String Length', $minLen);

        $all = $registry->all();

        self::assertSame(['Not Empty', 'Minimum String Length'], array_keys($all));
        self::assertSame($notEmpty, $all['Not Empty']);
        self::assertSame($minLen, $all['Minimum String Length']);
    }

    public function test_get_returns_null_for_unknown_key(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());

        self::assertNull($registry->get('No Such Rule'));
        self::assertNull($registry->get('Basilicom\\DataQualityBundle\\Definition\\NoSuchDefinition'));
        self::assertFalse($registry->has('No Such Rule'));
    }

    public function test_register_same_key_twice_overwrites_silently(): void
    {
        $registry = new RuleRegistry();
        $a = new NotEmptyDefinition();
        $b = new NotEmptyDefinition();

        $registry->register('X', $a);
        $registry->register('X', $b);

        self::assertSame($b, $registry->get('X'));
    }
}
