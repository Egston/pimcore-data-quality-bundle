<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Stubs;

use Pimcore\Model\DataObject\Concrete;

/**
 * Bypasses `Concrete`'s constructor to avoid touching the DI container,
 * while still satisfying `instanceof Concrete` guards. The
 * `setDataQualityScore()` method matches the `set<FieldName>` convention
 * that `setDataQualityPercent()` looks up via `method_exists()`.
 */
final class PercentRecordingDataObject extends Concrete
{
    public bool $setterCalled = false;
    public ?float $setterValue = null;

    public function __construct()
    {
        // Skip Concrete's constructor — it touches Pimcore::getContainer().
    }

    public function setDataQualityScore(?float $value): void
    {
        $this->setterCalled = true;
        $this->setterValue = $value;
    }
}
