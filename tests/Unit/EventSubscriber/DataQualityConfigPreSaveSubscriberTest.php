<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\EventSubscriber;

use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\GateFactory;
use Basilicom\DataQualityBundle\EventSubscriber\DataQualityConfigPreSaveSubscriber;
use PHPUnit\Framework\TestCase;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\ElementEventInterface;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData;
use Pimcore\Model\Element\ValidationException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class SpyGateFactory extends GateFactory
{
    /** @var string[] */
    public array $calls = [];

    public function fromString(?string $gate): Gate
    {
        $this->calls[] = (string) $gate;

        return parent::fromString($gate);
    }
}

final class DataQualityConfigPreSaveSubscriberTest extends TestCase
{
    public function test_empty_gate_strings_do_not_invoke_factory(): void
    {
        $spy = $this->makeSpyFactory();
        $subscriber = new DataQualityConfigPreSaveSubscriber($spy);
        $event = $this->makeEvent([
            $this->makeRule('name', null),
            $this->makeRule('description', ''),
        ]);

        $subscriber->onPreSave($event);

        self::assertSame([], $spy->calls, 'empty/null gates must skip factory entirely');
    }

    public function test_known_keyword_invokes_factory_for_each_non_empty_gate(): void
    {
        $spy = $this->makeSpyFactory();
        $subscriber = new DataQualityConfigPreSaveSubscriber($spy);
        $event = $this->makeEvent([
            $this->makeRule('name', 'source_filled'),
            $this->makeRule('description', 'any_lang_filled'),
            $this->makeRule('subtitle', 'published'),
        ]);

        $subscriber->onPreSave($event);

        self::assertSame(['source_filled', 'any_lang_filled', 'published'], $spy->calls);
    }

    public function test_unknown_keyword_throws_validation_exception(): void
    {
        $subscriber = $this->makeSubscriber();
        $event = $this->makeEvent([
            $this->makeRule('name', 'foobar_unknown'),
        ]);

        try {
            $subscriber->onPreSave($event);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('foobar_unknown', $e->getMessage());
            self::assertStringContainsString('name', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'underlying InvalidGateException must be preserved as $previous');
        }
    }

    public function test_invalid_expression_throws_validation_exception(): void
    {
        $subscriber = $this->makeSubscriber();
        $event = $this->makeEvent([
            $this->makeRule('field', 'expr:foo bar baz'),
        ]);

        try {
            $subscriber->onPreSave($event);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('expr:foo bar baz', $e->getMessage());
            self::assertStringContainsString('field', $e->getMessage());
        }
    }

    public function test_non_data_quality_config_short_circuits(): void
    {
        $subscriber = $this->makeSubscriber();
        $object = new class () extends AbstractObject {
            public function __construct()
            {
            }

            public function getDataQualityRules(): never
            {
                throw new \LogicException('subscriber must short-circuit before touching getDataQualityRules()');
            }
        };
        $event = new DataObjectEvent($object);

        $subscriber->onPreSave($event);
        self::assertTrue(true, 'short-circuit verified by getDataQualityRules: never trap');
    }

    public function test_non_dataobject_event_short_circuits(): void
    {
        $subscriber = $this->makeSubscriber();
        $event = $this->createMock(ElementEventInterface::class);
        $event->expects(self::never())->method('getElement');

        $subscriber->onPreSave($event);
        self::assertTrue(true, 'subscriber returned without touching event element');
    }

    public function test_null_rules_collection_short_circuits(): void
    {
        $spy = $this->makeSpyFactory();
        $subscriber = new DataQualityConfigPreSaveSubscriber($spy);
        $config = new DataQualityConfig();
        $config->setDataQualityRules(null);
        $event = new DataObjectEvent($config);

        $subscriber->onPreSave($event);

        self::assertSame([], $spy->calls, 'null rules collection must skip factory entirely');
    }

    public function test_first_invalid_gate_short_circuits_remaining_rules(): void
    {
        $subscriber = $this->makeSubscriber();
        $event = $this->makeEvent([
            $this->makeRule('first', 'source_filled'),
            $this->makeRule('second', 'foobar_unknown'),
            $this->makeRule('third', 'also_invalid'),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('foobar_unknown');

        $subscriber->onPreSave($event);
    }

    private function makeSubscriber(): DataQualityConfigPreSaveSubscriber
    {
        return new DataQualityConfigPreSaveSubscriber(new GateFactory(new ExpressionLanguage()));
    }

    private function makeSpyFactory(): SpyGateFactory
    {
        return new SpyGateFactory(new ExpressionLanguage());
    }

    /**
     * @param AbstractData[] $rules
     */
    private function makeEvent(array $rules): DataObjectEvent
    {
        $config = new DataQualityConfig();
        $config->setDataQualityRules(new Fieldcollection($rules, 'dataQualityRules'));

        return new DataObjectEvent($config);
    }

    private function makeRule(string $field, ?string $gate): AbstractData
    {
        return new class ($field, $gate) extends AbstractData {
            public function __construct(
                private readonly string $fieldValue,
                private readonly ?string $gateValue,
            ) {
            }

            public function getField(): ?string
            {
                return $this->fieldValue;
            }

            public function getGate(): ?string
            {
                return $this->gateValue;
            }
        };
    }
}
