<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Helper;

use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Kernel-free `RuleContext` factory. Builds an unproxied instance with
 * placeholder dependency instances so unit tests can pass a value-shape
 * 4th arg through `DefinitionInterface::validate()` without booting
 * Pimcore or wiring real `Concrete` / `DataQualityConfig` / resolver
 * instances.
 *
 * The returned context's accessors are not exercised by signature-pin
 * tests — only `RuleContextTest` exercises the surface, and that test
 * builds a more fully-wired context with a counting double for the
 * resolver.
 */
final class RuleContextFactory
{
    public static function stub(): RuleContext
    {
        $reflection = new \ReflectionClass(RuleContext::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        self::setReadonly($reflection, $instance, 'object', self::placeholder(Concrete::class));
        self::setReadonly($reflection, $instance, 'config', self::placeholder(DataQualityConfig::class));
        self::setReadonly($reflection, $instance, 'resolver', new class implements FieldPathResolverInterface {
            public function resolve(Concrete $object, string $path, string $language): array
            {
                return [];
            }
        });
        self::setReadonly($reflection, $instance, 'flagsProvider', null);
        self::setReadonly($reflection, $instance, 'languageScope', new LanguageScope('en', ['en', 'de'], ['en', 'de']));

        return $instance;
    }

    private static function placeholder(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function setReadonly(\ReflectionClass $class, object $instance, string $name, mixed $value): void
    {
        $prop = $class->getProperty($name);
        $prop->setValue($instance, $value);
    }
}
