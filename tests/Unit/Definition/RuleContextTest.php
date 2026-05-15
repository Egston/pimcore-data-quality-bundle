<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Resolver\ResolvedLeaf;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

final class RuleContextTest extends TestCase
{
    public function test_get_value_memoises_per_path_and_language(): void
    {
        $resolver = new CountingResolver();
        $context = $this->buildContext($resolver);

        $first = $context->getValue('name', 'de');
        $second = $context->getValue('name', 'de');
        $other = $context->getValue('name', 'en');

        self::assertSame(2, $resolver->callCount, 'expected one resolve per (path, language) pair');
        self::assertSame(
            [['name', 'de'], ['name', 'en']],
            $resolver->calls,
        );
        self::assertSame('name:de', $first);
        self::assertSame('name:de', $second, 'second call must return the cached value, not re-resolve');
        self::assertSame('name:en', $other, 'different language must produce distinct cached value');
    }

    public function test_values_by_lang_memoises_by_path(): void
    {
        $resolver = new CountingResolver();
        $context = $this->buildContext($resolver, allLanguages: ['en', 'de', 'fr']);

        $first = $context->getValuesByLang('name');
        $second = $context->getValuesByLang('name');

        self::assertSame($first, $second);
        self::assertSame(3, $resolver->callCount, 'first call iterates languages; second call hits the cache');
        self::assertSame(['en' => 'name:en', 'de' => 'name:de', 'fr' => 'name:fr'], $first);
    }

    public function test_values_by_lang_iterates_all_languages_not_scored(): void
    {
        $resolver = new CountingResolver();
        $context = $this->buildContext(
            $resolver,
            scoredLanguages: ['en', 'de'],
            allLanguages: ['en', 'de', 'fr'],
        );

        $result = $context->getValuesByLang('name');

        self::assertArrayHasKey('fr', $result, 'getValuesByLang must include languages outside scored set');
        self::assertSame(['en' => 'name:en', 'de' => 'name:de', 'fr' => 'name:fr'], $result);
    }

    public function test_container_leaves_memoises_per_container_path(): void
    {
        $resolver = new CountingResolver();
        $context = $this->buildContext($resolver);

        $first = $context->getContainerLeaves('sections');
        $countAfterFirst = $resolver->callCount;
        $second = $context->getContainerLeaves('sections');

        self::assertSame($first, $second);
        self::assertSame($countAfterFirst, $resolver->callCount, 'second call must not re-enter the resolver');
    }

    public function test_get_flags_by_lang_returns_null_when_no_provider_configured(): void
    {
        $resolver = new CountingResolver();
        $context = $this->buildContext($resolver, flagsProvider: null);

        self::assertNull($context->getFlagsByLang());
        self::assertNull($context->getFlagsByLang(), 'second call must also return null without faulting');
    }

    public function test_get_flags_by_lang_invokes_provider_exactly_once(): void
    {
        $provider = new class () implements LanguageFlagsProvider {
            public int $calls = 0;

            public function getName(): string
            {
                return 'counting';
            }

            public function getFlags(Concrete $object): array
            {
                $this->calls++;

                return ['de' => true, 'en' => false];
            }

            public function supportedClassIds(): array
            {
                return [];
            }
        };
        $context = $this->buildContext(new CountingResolver(), flagsProvider: $provider);

        $first = $context->getFlagsByLang();
        $second = $context->getFlagsByLang();

        self::assertSame($first, $second);
        self::assertSame(1, $provider->calls, 'flags provider must be hit exactly once per context lifetime');
    }

    public function test_container_leaves_iterates_scored_not_all_languages(): void
    {
        $resolver = new CountingResolver();
        $context = $this->buildContext(
            $resolver,
            scoredLanguages: ['en', 'de'],
            allLanguages: ['en', 'de', 'fr'],
        );

        $context->getContainerLeaves('sections');

        self::assertSame(2, $resolver->callCount, 'getContainerLeaves must iterate scoredLanguages, not allLanguages');
        self::assertSame([['sections', 'en'], ['sections', 'de']], $resolver->calls);
    }

    public function test_get_value_caches_null_result_and_does_not_re_resolve(): void
    {
        $resolver = new EmptyLeafCountingResolver();
        $context = $this->buildContext($resolver);

        $first = $context->getValue('missing', 'de');
        $second = $context->getValue('missing', 'de');

        self::assertNull($first);
        self::assertNull($second);
        self::assertSame(1, $resolver->callCount, 'null result must be cached; second call must not re-enter resolver');
    }

    public function test_get_flags_by_lang_memoises_empty_map_via_probed_sentinel(): void
    {
        $provider = new class () implements LanguageFlagsProvider {
            public int $calls = 0;

            public function getName(): string
            {
                return 'empty';
            }

            public function getFlags(Concrete $object): array
            {
                $this->calls++;

                return [];
            }

            public function supportedClassIds(): array
            {
                return [];
            }
        };
        $context = $this->buildContext(new CountingResolver(), flagsProvider: $provider);

        $first = $context->getFlagsByLang();
        $second = $context->getFlagsByLang();

        self::assertSame([], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $provider->calls, 'provider must be hit exactly once even when it returns an empty map');
    }

    public function test_get_value_throws_when_resolver_returns_multiple_leaves(): void
    {
        $resolver = new MultiLeafResolver();
        $context = $this->buildContext($resolver);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('getContainerLeaves');

        $context->getValue('sections', 'de');
    }

    public function test_scored_and_all_languages_default_to_provider_input(): void
    {
        $context = $this->buildContext(
            new CountingResolver(),
            scoredLanguages: ['en', 'de'],
            allLanguages: ['en', 'de', 'fr'],
        );

        self::assertSame(['en', 'de'], $context->getScoredLanguages());
        self::assertSame(['en', 'de', 'fr'], $context->getAllLanguages());
    }

    public function test_source_language_is_returned_verbatim(): void
    {
        $context = $this->buildContext(
            new CountingResolver(),
            sourceLanguage: 'ja',
            scoredLanguages: ['ja'],
            allLanguages: ['ja', 'en'],
        );

        self::assertSame('ja', $context->getSourceLanguage());
    }

    /**
     * @param string[] $scoredLanguages
     * @param string[] $allLanguages
     */
    private function buildContext(
        FieldPathResolverInterface $resolver,
        ?LanguageFlagsProvider $flagsProvider = null,
        string $sourceLanguage = 'en',
        array $scoredLanguages = ['en', 'de'],
        array $allLanguages = ['en', 'de'],
    ): RuleContext {
        $reflection = new \ReflectionClass(RuleContext::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('object')->setValue(
            $instance,
            (new \ReflectionClass(Concrete::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('config')->setValue(
            $instance,
            (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('resolver')->setValue($instance, $resolver);
        $reflection->getProperty('flagsProvider')->setValue($instance, $flagsProvider);
        $reflection->getProperty('languageScope')->setValue(
            $instance,
            new LanguageScope($sourceLanguage, $scoredLanguages, $allLanguages),
        );

        return $instance;
    }
}

final class CountingResolver implements FieldPathResolverInterface
{
    public int $callCount = 0;

    /** @var array<int, array{0: string, 1: string}> */
    public array $calls = [];

    public function resolve(Concrete $object, string $path, string $language): array
    {
        $this->callCount++;
        $this->calls[] = [$path, $language];

        return [new ResolvedLeaf($path, $language, $path . ':' . $language)];
    }
}

/** Resolver that always returns zero leaves — used to pin null caching. */
final class EmptyLeafCountingResolver implements FieldPathResolverInterface
{
    public int $callCount = 0;

    public function resolve(Concrete $object, string $path, string $language): array
    {
        $this->callCount++;

        return [];
    }
}

/** Resolver that always returns two leaves — used to pin the multi-leaf guard in getValue(). */
final class MultiLeafResolver implements FieldPathResolverInterface
{
    public function resolve(Concrete $object, string $path, string $language): array
    {
        return [
            new ResolvedLeaf($path . '[0]', $language, 'a'),
            new ResolvedLeaf($path . '[1]', $language, 'b'),
        ];
    }
}
