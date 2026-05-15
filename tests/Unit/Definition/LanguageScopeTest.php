<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\LanguageScope;
use PHPUnit\Framework\TestCase;

final class LanguageScopeTest extends TestCase
{
    public function test_constructor_rejects_source_not_in_all(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"ja"');

        new LanguageScope('ja', ['en'], ['en', 'de']);
    }

    public function test_constructor_rejects_scored_not_in_all(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"fr"');

        new LanguageScope('en', ['en', 'fr'], ['en', 'de']);
    }

    public function test_constructor_rejects_empty_all(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LanguageScope('en', [], []);
    }

    public function test_constructor_rejects_empty_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LanguageScope('', ['en'], ['en']);
    }

    public function test_accessors_round_trip(): void
    {
        $scope = new LanguageScope('en', ['en', 'de'], ['en', 'de', 'fr']);

        self::assertSame('en', $scope->getSourceLanguage());
        self::assertSame(['en', 'de'], $scope->getScoredLanguages());
        self::assertSame(['en', 'de', 'fr'], $scope->getAllLanguages());
    }

    public function test_empty_scored_is_permitted_for_callers_that_resolve_fallback_themselves(): void
    {
        $scope = new LanguageScope('en', [], ['en', 'de']);

        self::assertSame([], $scope->getScoredLanguages());
        self::assertSame(['en', 'de'], $scope->getAllLanguages());
    }
}
