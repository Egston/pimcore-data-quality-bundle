<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\EventSubscriber;

use Basilicom\DataQualityBundle\Definition\GateFactory;
use Basilicom\DataQualityBundle\Definition\InvalidGateException;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\ElementEventInterface;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\Element\ValidationException;

/**
 * Save-time gate-syntax validator. Walks every rule on the incoming
 * `DataQualityConfig` and asks `GateFactory::fromString()` to parse
 * each gate; an `InvalidGateException` is wrapped as a
 * `Pimcore\Model\Element\ValidationException` so the admin editor
 * renders the failure inline rather than as a generic 500.
 *
 * The bundle's existing `ObjectPreSaveListener` swallows arbitrary
 * exceptions on the same events; this listener's `ValidationException`
 * is uncaught by the other listener because that one filters to
 * objects whose class has DQ configs (and the `DataQualityConfig`
 * class is not itself DQ-scored). Two independent listeners on the
 * same event, each filtering before touching event state.
 */
final class DataQualityConfigPreSaveSubscriber
{
    public function __construct(private readonly GateFactory $gateFactory)
    {
    }

    /**
     * @throws ValidationException when any rule's gate string fails to parse
     */
    public function onPreSave(ElementEventInterface $event): void
    {
        if (!$event instanceof DataObjectEvent) {
            return;
        }

        $dataObject = $event->getElement();
        if (!$dataObject instanceof DataQualityConfig) {
            return;
        }

        $rules = $dataObject->getDataQualityRules();
        if ($rules === null) {
            return;
        }

        foreach ($rules->getItems() as $item) {
            $gate = $item->getGate();
            if ($gate === null || $gate === '') {
                continue;
            }

            try {
                $this->gateFactory->fromString($gate);
            } catch (InvalidGateException $e) {
                $field = (string) $item->getField();

                throw new ValidationException(
                    sprintf(
                        'Invalid gate "%s" on rule "%s": %s',
                        $gate,
                        $field,
                        $e->getMessage(),
                    ),
                    0,
                    $e,
                );
            }
        }
    }
}
