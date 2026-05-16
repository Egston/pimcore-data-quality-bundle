<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\GridOperator;

use Basilicom\DataQualityBundle\GridOperator\Quality;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ConfigElementInterface;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer;
use Pimcore\Model\Element\ElementInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins the grid operator's null-handling. The percentage column reads SQL
 * NULL for unscored rows; without a null branch the cell would coerce to
 * `0` and render a red-tinted "0%", visually indistinguishable from a
 * genuinely-failing row.
 */
final class QualityTest extends TestCase
{
    public function test_renders_em_dash_for_null_child_value(): void
    {
        $operator = $this->buildOperator($this->childReturning(null));

        $result = $operator->getLabeledValue($this->stubElement());

        self::assertStringContainsString('—', $result->value);
        self::assertStringNotContainsString('%', $result->value);
        self::assertStringNotContainsString('background-color', $result->value);
    }

    public function test_renders_em_dash_for_empty_string_child_value(): void
    {
        $operator = $this->buildOperator($this->childReturning(''));

        $result = $operator->getLabeledValue($this->stubElement());

        self::assertStringContainsString('—', $result->value);
        self::assertStringNotContainsString('%', $result->value);
    }

    public function test_renders_color_coded_percent_for_numeric_value(): void
    {
        $operator = $this->buildOperator($this->childReturning('75'));

        $result = $operator->getLabeledValue($this->stubElement());

        self::assertStringContainsString('75%', $result->value);
        self::assertStringContainsString('background-color', $result->value);
    }

    public function test_renders_zero_percent_with_red_for_real_zero(): void
    {
        $operator = $this->buildOperator($this->childReturning('0'));

        $result = $operator->getLabeledValue($this->stubElement());

        self::assertStringContainsString('0%', $result->value);
        self::assertStringContainsString('#FFA0A0', $result->value);
    }

    private function buildOperator(ConfigElementInterface $child): Quality
    {
        $config = new stdClass();
        $config->label = 'Quality';
        $config->children = [$child];

        return new Quality($config);
    }

    private function childReturning(?string $value): ConfigElementInterface
    {
        return new class ($value) implements ConfigElementInterface {
            public function __construct(private readonly ?string $value) {}

            public function getLabel(): string
            {
                return 'percentage';
            }

            public function getLabeledValue(array|ElementInterface $element): ResultContainer|stdClass|null
            {
                $r = new stdClass();
                $r->value = $this->value;

                return $r;
            }

            public function getRenderer(): ?string
            {
                return null;
            }
        };
    }

    private function stubElement(): ElementInterface
    {
        return $this->createStub(ElementInterface::class);
    }
}
