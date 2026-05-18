<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tools;

use Doctrine\DBAL\Connection;
use Pimcore\Extension\Bundle\Installer\Exception\InstallationException;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Service;
use Pimcore\Model\DataObject\Fieldcollection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

class Installer extends SettingsStoreAwareInstaller
{
    /**
     * Pimcore-managed bookkeeping columns on `object_store_<id>` and
     * `object_query_<id>` tables. These are part of the table's contract
     * with Pimcore and are not described by class-definition JSON, so the
     * column-drop pre-flight check excludes them from comparison.
     */
    private const CLASS_TABLE_BOOKKEEPING = ['oo_id', 'oo_classId', 'oo_className'];

    /**
     * Pimcore-managed bookkeeping columns on `object_collection_<key>_<id>`
     * tables.
     */
    private const FIELDCOLLECTION_TABLE_BOOKKEEPING = ['id', 'index', 'fieldname'];

    /**
     * Suffixes for Pimcore field types that materialise as multiple columns
     * (e.g. image fields adding `<name>__hash` and `<name>__type`). When the
     * base name is in the JSON, sibling `__hash` / `__type` / `__metadata`
     * columns are accepted as managed by the same field definition.
     */
    private const AUXILIARY_COLUMN_SUFFIXES = ['__hash', '__type', '__metadata'];

    private string $installSourcesPath;
    private array $classesToInstall = [
        'DataQualityConfig' => 'DQC',
    ];

    public function __construct(
        BundleInterface $bundle,
        private readonly Connection $connection,
    ) {
        $this->installSourcesPath = __DIR__ . '/../Resources/install';

        parent::__construct($bundle);
    }

    public function install(): void
    {
        $this->installFieldCollection();
        $this->installClasses();

        parent::install();
    }

    public function uninstall(): void
    {
        $this->uninstallClasses();
        $this->uninstallFieldCollection();

        parent::uninstall();
    }

    /**
     * Re-import the bundle's class + fieldcollection install JSONs onto the
     * already-installed definitions. Drives the underlying `Definition::save()`
     * which runs the necessary `ALTER TABLE` DDL for added / removed columns
     * and regenerates the generated PHP classes under `var/classes/`.
     *
     * Pimcore 11 ships `pimcore:bundle:install` / `:uninstall` but no `:update`
     * hook, and `install()` is refused once the bundle is marked installed
     * (`SettingsStoreAwareInstaller::canBeInstalled()` returns false). This
     * method is the schema-only re-sync path, invoked via the
     * `dataquality:resync-schema` console command.
     */
    public function resyncSchema(): void
    {
        $this->resyncFieldCollections();
        $this->installClasses();
    }

    private function getClassesToInstall(): array
    {
        $result = [];
        foreach (\array_keys($this->classesToInstall) as $className) {
            $filename = \sprintf('class_%s_export.json', $className);
            $path     = $this->installSourcesPath . '/class_sources/' . $filename;
            $path     = \realpath($path);

            if (false === $path || !\is_file($path)) {
                throw new InstallationException(\sprintf(
                    'Class export for class "%s" was expected in "%s" but file does not exist',
                    $className,
                    $path
                ));
            }

            $result[$className] = $path;
        }

        return $result;
    }

    public function installClasses()
    {
        $classes = $this->getClassesToInstall();
        $mapping = $this->classesToInstall;

        foreach ($classes as $key => $path) {
            $class = ClassDefinition::getByName($key);
            if ($class === null) {
                $class = new ClassDefinition();
                $classId = $mapping[$key];

                $class->setName($key);
                $class->setId($classId);
            }

            $data    = \file_get_contents($path);
            $this->assertModernSchemaJson($key, $path, $data);

            $expectedColumns = self::expectedColumnNamesFromJson($data);
            $classId         = $mapping[$key];
            foreach (['object_store_' . $classId, 'object_query_' . $classId] as $table) {
                $this->assertNoColumnDrop($key, $table, $expectedColumns, self::CLASS_TABLE_BOOKKEEPING);
            }

            $success = Service::importClassDefinitionFromJson($class, $data, false, true);

            if (!$success) {
                throw new InstallationException(\sprintf(
                    'Failed to create class "%s"',
                    $key
                ));
            }
        }
    }

    private function uninstallClasses()
    {
        $classes = $this->getClassesToInstall();

        foreach ($classes as $key => $path) {
            $class = ClassDefinition::getByName($key);

            if ($class) {
                $class->delete();

                continue;
            }

            $this->getOutput()->write(\sprintf(
                '     <comment>WARNING:</comment> Skipping class "%s" as it doesn\'t exists',
                $key
            ));
        }
    }

    private function installFieldCollection()
    {
        $fieldcollections = $this->findInstallFiles(
            $this->installSourcesPath . '/fieldcollection_sources',
            '/^fieldcollection_(.*)_export\.json$/'
        );

        foreach ($fieldcollections as $key => $path) {
            if (Fieldcollection\Definition::getByKey($key)) {
                $this->getOutput()->write(\sprintf(
                    '     <comment>WARNING:</comment> Skipping fieldcollection "%s" as it already exists',
                    $key
                ));

                continue;
            }

            $fieldcollection = new Fieldcollection\Definition();
            $fieldcollection->setKey($key);

            $data    = \file_get_contents($path);
            $this->assertModernSchemaJson($key, $path, $data);

            $expectedColumns = self::expectedColumnNamesFromJson($data);
            foreach ($this->findFieldcollectionTables($key) as $table) {
                $this->assertNoColumnDrop($key, $table, $expectedColumns, self::FIELDCOLLECTION_TABLE_BOOKKEEPING);
            }

            $success = Service::importFieldCollectionFromJson($fieldcollection, $data);

            if (!$success) {
                throw new InstallationException(\sprintf(
                    'Failed to create object fieldcollection "%s"',
                    $key
                ));
            }
        }
    }

    /**
     * Reject install JSONs that still use the legacy Pimcore `<= 10` `"childs"`
     * key for layout children. Pimcore 11's import path
     * (`Service::generateLayoutTreeFromArray`) only reads `"children"` and
     * the `"childs"` key was deprecated without an alias — passing such a
     * JSON to the importer silently produces an empty class definition,
     * which `Definition::save()` then realises by issuing
     * `ALTER TABLE ... DROP COLUMN` for every "removed" field. That
     * destroys data on existing installs.
     *
     * Substring check rather than parsed-tree walk because the failure mode
     * is binary: any occurrence of `"childs":` taints the entire JSON and
     * makes the import unsafe to run.
     */
    private function assertModernSchemaJson(string $key, string $path, string $json): void
    {
        if (\str_contains($json, '"childs":')) {
            throw new InstallationException(\sprintf(
                'Refusing to import "%s" from %s: JSON contains the legacy '
                . '"childs" layout key (Pimcore <= 10). Pimcore 11+ only reads '
                . '"children"; importing as-is would silently produce an empty '
                . 'definition and DROP existing columns. Re-export from the '
                . 'Pimcore 11 admin (which writes "children") or rename keys '
                . 'before retrying.',
                $key,
                $path
            ));
        }
    }

    /**
     * Walk a class / fieldcollection install JSON and return the list of
     * column-bearing data-field names. Layout-only nodes (panel, region,
     * fieldset, plain `text` labels, etc.) are excluded — they have no
     * corresponding DB column.
     *
     * Public static so it is independently testable from the kernel-free
     * PHPUnit suite without instantiating the Installer or booting Pimcore.
     */
    public static function expectedColumnNamesFromJson(string $json): array
    {
        $data = \json_decode($json, true);
        if (!\is_array($data) || !isset($data['layoutDefinitions']) || !\is_array($data['layoutDefinitions'])) {
            return [];
        }

        $names = [];
        self::collectFieldNamesRecursive($data['layoutDefinitions'], $names);

        return \array_values(\array_unique($names));
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $names
     */
    private static function collectFieldNamesRecursive(array $node, array &$names): void
    {
        $layoutFieldtypes = [
            'panel', 'tabpanel', 'region', 'accordion', 'fieldset',
            'fieldcontainer', 'tab', 'button', 'text', 'iframe', 'spacer',
        ];

        $fieldtype = $node['fieldtype'] ?? null;
        $name      = $node['name'] ?? null;
        if (\is_string($fieldtype) && \is_string($name) && !\in_array($fieldtype, $layoutFieldtypes, true)) {
            $names[] = $name;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (\is_array($child)) {
                self::collectFieldNamesRecursive($child, $names);
            }
        }
    }

    /**
     * Refuse to import a definition when its JSON omits a column that
     * currently exists on the target DB table. `Definition::save()` would
     * realise the diff as `ALTER TABLE ... DROP COLUMN`, destroying any
     * data in those columns. The drop may still be the right thing — e.g.
     * a field really was removed — but the right path then is an explicit
     * `ALTER TABLE` or admin-driven removal, not a silent re-import.
     *
     * Bookkeeping columns (`oo_id`, `id`, `fieldname`, …) and Pimcore
     * auxiliary suffix columns (`<base>__hash`, `<base>__type`,
     * `<base>__metadata` when `<base>` is in the JSON) are excluded from
     * the comparison.
     *
     * @param list<string> $expectedColumns
     * @param list<string> $bookkeeping
     */
    private function assertNoColumnDrop(string $importKey, string $tableName, array $expectedColumns, array $bookkeeping): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$tableName])) {
            return; // Fresh install — no existing columns to compare against.
        }

        $existing       = $schemaManager->listTableColumns($tableName);
        $existingNames  = \array_map(static fn ($c) => $c->getName(), $existing);
        $auxiliaryNames = [];
        foreach ($existingNames as $columnName) {
            foreach (self::AUXILIARY_COLUMN_SUFFIXES as $suffix) {
                if (\str_ends_with($columnName, $suffix)) {
                    $base = \substr($columnName, 0, -\strlen($suffix));
                    if (\in_array($base, $expectedColumns, true)) {
                        $auxiliaryNames[] = $columnName;
                    }
                }
            }
        }

        $wouldDrop = \array_diff($existingNames, $expectedColumns, $bookkeeping, $auxiliaryNames);
        if (!empty($wouldDrop)) {
            throw new InstallationException(\sprintf(
                'Refusing to import "%s": columns [%s] exist on table `%s` but are not declared in the install JSON. '
                . 'Re-importing would issue ALTER TABLE DROP COLUMN and destroy any data in those columns. '
                . 'If the removal is intentional, drop the columns manually first (or uninstall + reinstall the bundle).',
                $importKey,
                \implode(', ', $wouldDrop),
                $tableName
            ));
        }
    }

    /**
     * Discover every `object_collection_<key>_<class-id>` table that exists
     * for the given fieldcollection key. Pimcore creates one such table per
     * class that references the fieldcollection.
     *
     * @return list<string>
     */
    private function findFieldcollectionTables(string $fieldcollectionKey): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tables        = $schemaManager->listTableNames();
        $prefix        = 'object_collection_' . $fieldcollectionKey . '_';

        return \array_values(\array_filter($tables, static fn ($t) => \str_starts_with($t, $prefix)));
    }

    private function resyncFieldCollections(): void
    {
        $fieldcollections = $this->findInstallFiles(
            $this->installSourcesPath . '/fieldcollection_sources',
            '/^fieldcollection_(.*)_export\.json$/'
        );

        foreach ($fieldcollections as $key => $path) {
            $fieldcollection = Fieldcollection\Definition::getByKey($key);
            if (!$fieldcollection) {
                $fieldcollection = new Fieldcollection\Definition();
                $fieldcollection->setKey($key);
            }

            $data    = \file_get_contents($path);
            $this->assertModernSchemaJson($key, $path, $data);

            $expectedColumns = self::expectedColumnNamesFromJson($data);
            foreach ($this->findFieldcollectionTables($key) as $table) {
                $this->assertNoColumnDrop($key, $table, $expectedColumns, self::FIELDCOLLECTION_TABLE_BOOKKEEPING);
            }

            $success = Service::importFieldCollectionFromJson($fieldcollection, $data);

            if (!$success) {
                throw new InstallationException(\sprintf(
                    'Failed to re-import fieldcollection "%s"',
                    $key
                ));
            }
        }
    }

    private function uninstallFieldCollection()
    {
        $fieldcollections = $this->findInstallFiles(
            $this->installSourcesPath . '/fieldcollection_sources',
            '/^fieldcollection_(.*)_export\.json$/'
        );

        foreach ($fieldcollections as $key => $path) {
            if ($fieldcollection = Fieldcollection\Definition::getByKey($key)) {
                $fieldcollection->delete();

                continue;
            }

            $this->getOutput()->write(sprintf(
                '     <comment>WARNING:</comment> Skipping fieldcollection "%s" as it doesn\'t exists',
                $key
            ));
        }
    }

    /**
     * @param string $directory
     * @param string $pattern
     *
     * @return array
     */
    private function findInstallFiles(string $directory, string $pattern): array
    {
        $finder = new Finder();
        $finder->files()->in($directory)->name($pattern);

        $results = [];
        foreach ($finder as $file) {
            if (\preg_match($pattern, $file->getFilename(), $matches)) {
                $key           = $matches[1];
                $results[$key] = $file->getRealPath();
            }
        }

        return $results;
    }

    /**
     * @return bool
     */
    public function needsReloadAfterInstall(): bool
    {
        return true;
    }
}
