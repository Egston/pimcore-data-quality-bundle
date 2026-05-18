<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Tools;

use Basilicom\DataQualityBundle\Tools\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure JSON-walk path used by the column-drop pre-flight check.
 * The DB interaction path needs a kernel and lives in integration tests;
 * this suite stays kernel-free.
 */
class InstallerColumnDiscoveryTest extends TestCase
{
    public function testExtractsDataFieldNamesAndIgnoresLayoutContainers(): void
    {
        $json = \json_encode([
            'layoutDefinitions' => [
                'fieldtype' => 'panel',
                'name'      => 'pimcore_root',
                'children'  => [
                    [
                        'fieldtype' => 'panel',
                        'name'      => 'Layout',
                        'children'  => [
                            ['fieldtype' => 'text', 'name' => 'HeadingLabel'],
                            ['fieldtype' => 'input', 'name' => 'FieldA'],
                            ['fieldtype' => 'select', 'name' => 'FieldB'],
                            ['fieldtype' => 'numeric', 'name' => 'FieldC'],
                        ],
                    ],
                ],
            ],
        ]);

        $cols = Installer::expectedColumnNamesFromJson($json);

        \sort($cols);
        $this->assertSame(['FieldA', 'FieldB', 'FieldC'], $cols);
    }

    public function testReturnsEmptyForLegacyChildsSpelling(): void
    {
        // Pimcore 11 import path reads only `children`; a JSON that still uses
        // the legacy `childs` key produces zero data fields. This is the same
        // failure mode the spelling-guard catches via substring; here we
        // verify the column walker is consistent with that diagnosis.
        $json = \json_encode([
            'layoutDefinitions' => [
                'fieldtype' => 'panel',
                'name'      => 'pimcore_root',
                'childs'    => [
                    ['fieldtype' => 'input', 'name' => 'FieldA'],
                ],
            ],
        ]);

        $this->assertSame([], Installer::expectedColumnNamesFromJson($json));
    }

    public function testReturnsEmptyForMissingLayoutDefinitions(): void
    {
        $this->assertSame([], Installer::expectedColumnNamesFromJson('{}'));
        $this->assertSame([], Installer::expectedColumnNamesFromJson('{"layoutDefinitions": null}'));
    }

    public function testDeduplicatesFieldNamesAcrossSubtrees(): void
    {
        $json = \json_encode([
            'layoutDefinitions' => [
                'fieldtype' => 'panel',
                'name'      => 'root',
                'children'  => [
                    ['fieldtype' => 'input', 'name' => 'Same'],
                    [
                        'fieldtype' => 'fieldset',
                        'name'      => 'group',
                        'children'  => [
                            ['fieldtype' => 'input', 'name' => 'Same'],
                            ['fieldtype' => 'input', 'name' => 'Other'],
                        ],
                    ],
                ],
            ],
        ]);

        $cols = Installer::expectedColumnNamesFromJson($json);

        \sort($cols);
        $this->assertSame(['Other', 'Same'], $cols);
    }

    public function testMatchesShippedFieldcollectionExport(): void
    {
        // Anchors the walker against the bundle's own current install asset
        // so a regression in the JSON or the walker shows up as a test
        // failure rather than a silent ALTER TABLE at install time.
        $path = __DIR__ . '/../../../src/Resources/install/fieldcollection_sources/fieldcollection_DataQualityFieldDefinition_export.json';
        $cols = Installer::expectedColumnNamesFromJson(\file_get_contents($path));

        \sort($cols);
        $this->assertSame(
            ['Condition', 'Field', 'Gate', 'Group', 'Parameters', 'Weight'],
            $cols
        );
    }

    public function testMatchesShippedClassExport(): void
    {
        $path = __DIR__ . '/../../../src/Resources/install/class_sources/class_DataQualityConfig_export.json';
        $cols = Installer::expectedColumnNamesFromJson(\file_get_contents($path));

        \sort($cols);
        $this->assertSame(
            ['DataQualityClass', 'DataQualityField', 'DataQualityLanguages', 'DataQualityName', 'DataQualityRules', 'DataQualitySystemAllowed'],
            $cols
        );
    }
}
