<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\PublishedGate;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;

final class PublishedGateTest extends TestCase
{
    public function test_applies_when_object_is_published(): void
    {
        $gate   = new PublishedGate();
        $object = $this->makeObject(published: true);

        self::assertTrue(
            $gate->evaluate(
                RuleContextBuilder::empty()->object($object)->build(),
                $this->makeFieldDef('name'),
            ),
        );
    }

    public function test_does_not_apply_when_object_is_draft(): void
    {
        $gate   = new PublishedGate();
        $object = $this->makeObject(published: false);

        self::assertFalse(
            $gate->evaluate(
                RuleContextBuilder::empty()->object($object)->build(),
                $this->makeFieldDef('name'),
            ),
        );
    }

    private function makeObject(bool $published): Concrete
    {
        $object = new class extends Concrete {
            public bool $pub = false;

            public function __construct() {}

            public function isPublished(): bool
            {
                return $this->pub;
            }
        };
        $object->pub = $published;

        return $object;
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
