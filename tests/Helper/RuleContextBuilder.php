<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Helper;

use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Resolver\ResolvedLeaf;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Builds a kernel-free `RuleContext` for gate unit tests.
 *
 * Provide a `valuesByLang` map; the resolver returns one `ResolvedLeaf`
 * per language entry, or an empty array when the language is absent.
 */
final class RuleContextBuilder
{
    /** @var array<string, mixed> */
    private array $valuesByLang = [];

    private string $sourceLanguage = 'en';

    /** @var string[]|null */
    private ?array $scoredLanguages = null;

    /** @var string[]|null */
    private ?array $allLanguages = null;

    private ?Concrete $object = null;

    /** @param array<string, mixed> $valuesByLang */
    public static function withValues(array $valuesByLang): self
    {
        $b = new self();
        $b->valuesByLang = $valuesByLang;

        return $b;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function sourceLanguage(string $lang): self
    {
        $clone = clone $this;
        $clone->sourceLanguage = $lang;

        return $clone;
    }

    /** @param string[] $langs */
    public function scoredLanguages(array $langs): self
    {
        $clone = clone $this;
        $clone->scoredLanguages = $langs;

        return $clone;
    }

    /** @param string[] $langs */
    public function allLanguages(array $langs): self
    {
        $clone = clone $this;
        $clone->allLanguages = $langs;

        return $clone;
    }

    public function object(Concrete $object): self
    {
        $clone = clone $this;
        $clone->object = $object;

        return $clone;
    }

    public function build(): RuleContext
    {
        $valuesByLang   = $this->valuesByLang;
        $allLanguages   = $this->allLanguages ?? (array_keys($valuesByLang) ?: ['en']);
        $scoredLanguages = $this->scoredLanguages ?? $allLanguages;

        $resolver = new class ($valuesByLang) implements FieldPathResolverInterface {
            public function __construct(private readonly array $values) {}

            public function resolve(Concrete $object, string $path, string $language): array
            {
                if (!array_key_exists($language, $this->values)) {
                    return [];
                }

                return [new ResolvedLeaf($path, $language, $this->values[$language])];
            }
        };

        $reflection = new \ReflectionClass(RuleContext::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $objectInstance = $this->object
            ?? (new \ReflectionClass(Concrete::class))->newInstanceWithoutConstructor();

        $reflection->getProperty('object')->setValue($instance, $objectInstance);
        $reflection->getProperty('config')->setValue(
            $instance,
            (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('resolver')->setValue($instance, $resolver);
        $reflection->getProperty('flagsProvider')->setValue($instance, null);
        $reflection->getProperty('languageScope')->setValue(
            $instance,
            new LanguageScope($this->sourceLanguage, $scoredLanguages, $allLanguages),
        );

        return $instance;
    }
}
