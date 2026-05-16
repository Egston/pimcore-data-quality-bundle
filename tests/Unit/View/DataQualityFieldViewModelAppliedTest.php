<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\View;

use Basilicom\DataQualityBundle\View\DataQualityFieldViewModel;
use PHPUnit\Framework\TestCase;

/**
 * Pins the `applied` field of `DataQualityFieldViewModel`. Default is
 * `true` so existing callers preserve their pre-gate semantics; the
 * percentage math reads `isApplied()` and skips both numerator and
 * denominator when it returns `false`.
 */
final class DataQualityFieldViewModelAppliedTest extends TestCase
{
    public function test_applied_defaults_to_true(): void
    {
        $model = new DataQualityFieldViewModel('title', 1, true);

        self::assertTrue($model->isApplied());
    }

    public function test_applied_can_be_set_to_false(): void
    {
        $model = new DataQualityFieldViewModel('title', 1, true, null, null, false);

        self::assertFalse($model->isApplied());
    }

    public function test_not_applied_and_not_valid_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);

        new DataQualityFieldViewModel('title', 1, false, null, null, false);
    }

    public function test_existing_accessors_unchanged(): void
    {
        $model = new DataQualityFieldViewModel('title', 3, false, 'de', ['en' => true]);

        self::assertSame('title', $model->getName());
        self::assertSame(3, $model->getWeight());
        self::assertFalse($model->isValid());
        self::assertSame('de', $model->getLanguage());
        self::assertSame(['en' => true], $model->getValidFields());
        self::assertTrue($model->isApplied(), 'applied defaults to true when not passed');
    }
}
