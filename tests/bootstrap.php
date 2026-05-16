<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Pimcore\Model\DataObject\DataQualityConfig::class, false)) {
    require __DIR__ . '/Stubs/PimcoreDataQualityConfigStub.php';
}

if (!class_exists(\Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition::class, false)) {
    require __DIR__ . '/Stubs/PimcoreDataQualityFieldDefinitionStub.php';
}
