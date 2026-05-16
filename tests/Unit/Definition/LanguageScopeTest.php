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

    public function test_is_scored_returns_true_for_scored_language(): void
    {
        $scope = new LanguageScope('en', ['en', 'de'], ['en', 'de', 'fr']);

        self::assertTrue($scope->isScored('en'));
        self::assertTrue($scope->isScored('de'));
    }

    public function test_is_scored_returns_false_for_source_only_language(): void
    {
        $scope = new LanguageScope('en', ['de'], ['en', 'de']);

        self::assertFalse($scope->isScored('en'));
        self::assertFalse($scope->isScored('ja'));
    }

    public function test_source_only_languages_returns_all_minus_scored(): void
    {
        $scope = new LanguageScope('en', ['de', 'fr'], ['en', 'de', 'fr']);

        self::assertSame(['en'], $scope->getSourceOnlyLanguages());
    }

    public function test_source_only_languages_is_empty_when_all_languages_are_scored(): void
    {
        $scope = new LanguageScope('en', ['en', 'de'], ['en', 'de']);

        self::assertSame([], $scope->getSourceOnlyLanguages());
    }
}
