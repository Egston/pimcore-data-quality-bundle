<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

/**
 * Bundle-internal typed throw from `GateFactory::fromString()`. The
 * save-time subscriber (`DataQualityConfigPreSaveSubscriber`) is the
 * sole catch site and re-throws as
 * `\Pimcore\Model\Element\ValidationException` so the admin editor
 * renders the message inline; recompute-time callers must let the
 * exception propagate to their fail-open `\Throwable` catch.
 */
final class InvalidGateException extends \DomainException
{
    public function __construct(
        string $message,
        private readonly string $gateSource,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getGateSource(): string
    {
        return $this->gateSource;
    }
}
