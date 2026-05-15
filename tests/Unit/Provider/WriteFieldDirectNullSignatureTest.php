<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the `writeFieldDirect()` signature as nullable-float.
 * A real DB-side test would need the DBAL connection injected into the
 * provider (today the method calls static `Db::get()`); settling for a
 * reflective signature pin so any refactor that re-narrows the type
 * fails loudly here.
 */
final class WriteFieldDirectNullSignatureTest extends TestCase
{
    public function test_write_field_direct_accepts_nullable_float(): void
    {
        $reflection = new \ReflectionMethod(DataQualityProvider::class, 'writeFieldDirect');
        $params = $reflection->getParameters();

        self::assertCount(3, $params);
        self::assertSame('value', $params[2]->getName());

        $type = $params[2]->getType();
        self::assertNotNull($type);
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('float', $type->getName());
        self::assertTrue($type->allowsNull(), 'writeFieldDirect must accept null to support the all-N/A score path');
    }
}
