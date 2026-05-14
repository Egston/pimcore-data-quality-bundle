# CLAUDE.md

> **Note:** This repository is an **active fork** of [`basilicom/pimcore-data-quality-bundle`](https://github.com/basilicom/pimcore-data-quality-bundle), maintained by Yageo / Egston. It is an integral part of the Yageo Pimcore deployment. Feel free to modify it to fit Yageo's requirements.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`basilicom/pimcore-data-quality-bundle` is a Pimcore 11 bundle (PHP >=8.0) that computes weighted data quality scores for Pimcore data objects based on configurable validation rules. Scores are stored as numeric fields on the object and updated on save or via CLI.

## Installation & Setup (within a Pimcore project)

```bash
composer require basilicom/pimcore-data-quality-bundle
bin/console pimcore:bundle:enable DataQualityBundle
bin/console pimcore:bundle:install DataQualityBundle
```

## CLI Commands

```bash
# Recalculate data quality for all objects of a given config (batch processing via child processes)
bin/console dataquality:update <quality-config-id> <batch-size>

# Process every DataQualityConfig in one shot
bin/console dataquality:update-all <batch-size>
```

By default both commands use the **fast path**: a direct SQL UPDATE to `object_store_<cid>` and `object_query_<cid>`, bypassing the full Pimcore `save()` pipeline (no events, no version bump, no relation rewrite). This is safe and intentional — the DQ percentage is a denormalized cache, not a content edit.

Pass `--full-save` to opt into the full `save()` path (slower; fires all Pimcore events). The fast path skips `InheritanceHelper::saveChildData`, so non-leaf objects on inheritance-enabled classes auto-fall-back to full-save. Leaves always take the fast path.

## Architecture

### Bundle Entry Point
- `src/DataQualityBundle.php` — extends `AbstractPimcoreBundle`; services loaded via `src/DependencyInjection/DataQualityExtension.php` from `src/Resources/config/services.yml`

### Core Data Flow
1. **Save trigger**: `Model/Listener/ObjectPreSaveListener.php` listens to `pimcore.dataobject.preAdd` and `pimcore.dataobject.preUpdate`. It skips auto-saves and respects system user permissions (configurable per `DataQualityConfig` object).
2. **Orchestration**: `Service/DataQualityService.php` manages inheritance settings and delegates to the provider.
3. **Calculation**: `Provider/DataQualityProvider.php` applies weighted validation rules against each field (standard fields, localized fields across all system languages, and object bricks). Returns `DataQualityViewModel`.
4. **Storage**: The computed percentage is written back to the numeric field specified in the `DataQualityConfig` object.

### Validation Rules (Definitions)
Located in `src/Definition/`. Each implements `DefinitionInterface`:
- `NotEmptyDefinition` — field must not be empty
- `MinimumStringLengthDefinition` — string must meet minimum length (params are `;`-separated)

New definitions should extend `DefinitionAbstract` and implement `DefinitionInterface`. Register them in `DefinitionsCollection/DefinitionsCollection.php`.

### Configuration Objects (Pimcore Data Objects)
- **`DataQualityConfig`** — Pimcore data object class (installed via `Resources/install/`) that specifies: target class, target numeric field, rules (via `DataQualityFieldDefinition` field collection entries with field name, condition, weight, and optional group name).
- Rules are read at runtime from live Pimcore objects, not from YAML/PHP config files.

### Admin UI Integration
- **Event subscriber** (`EventSubscriber/PimcoreAdminSubscriber.php`): Adds the "Data Quality" tab to all data objects in the admin.
- **Layout component** (`Model/DataObject/ClassDefinition/Layout/DataQuality.php`): A custom layout panel type ("Data Quality") that can be added to class definitions in Pimcore admin.
- **Renderer** (`Model/Renderer/DataQualityConfigRenderer.php`): Renders the data-quality panel using `Resources/views/data-quality.html.twig`.
- **Controller** (`Controller/DataQualityController.php`): Provides AJAX endpoints for the admin panel to fetch quality status and check class configuration.
- **Grid operator** (`GridOperator/Quality.php`): Use class `Basilicom\DataQualityBundle\GridOperator\Quality` in Pimcore grid "Operator PHP Code" to render color-coded percentages.
- **Admin option providers** (`Model/Provider/`): `ObjectClassesProvider`, `ObjectFieldsProvider`, and `DefinitionsProvider` supply dynamic select-box options inside the `DataQualityConfig` object editor.

### Frontend (JS/CSS)
- `Resources/public/js/DataQualityBundle.js` — bundle init, registers the layout type and grid operator
- `Resources/public/js/pimcore/object/classes/layout/dataQuality.js` — class editor panel definition
- `Resources/public/js/pimcore/object/layout/dataQuality.js` — runtime layout renderer

### View Models
`View/DataQualityViewModel.php`, `View/DataQualityGroupViewModel.php`, `View/DataQualityFieldViewModel.php` — hierarchical result structure: overall score → groups → individual field results.

### Installer
`Tools/Installer.php` installs the `DataQualityConfig` data object class and the `DataQualityFieldDefinition` field collection from `Resources/install/` JSON exports. Re-running `pimcore:bundle:install` is needed after changes to these exports.

### Testing
Kernel-free PHPUnit suite under `tests/Unit/` covers pure rule/value-object logic. The bundle ships its own `require-dev` so install vendors locally and run the suite on the host:

```bash
composer install --ignore-platform-reqs
vendor/bin/phpunit
```

New tests mirror the namespace under `tests/Unit/<area>/` and instantiate Pimcore value classes directly when they're pure; otherwise stub `Pimcore\Model\DataObject\ClassDefinition\Data` via `createStub()`. Do not pull in `pimcore/testing` or boot the kernel here.

### Localized Fields Behavior
When a localized field rule is evaluated, it must be valid in **all** configured Pimcore system languages to count as valid. Language-specific rules can be set using the `#<lang>` suffix (e.g., `NameDE#de`); `#All` checks all languages.

## Key Files for Common Tasks

| Task | File(s) |
|------|---------|
| Add a new validation condition | `src/Definition/`, `src/DefinitionsCollection/DefinitionsCollection.php` |
| Change when quality is recalculated | `src/Model/Listener/ObjectPreSaveListener.php` |
| Change calculation logic | `src/Provider/DataQualityProvider.php` |
| Modify admin panel rendering | `src/Model/Renderer/DataQualityConfigRenderer.php`, `src/Resources/views/` |
| Modify admin JS behavior | `src/Resources/public/js/` |
| Add/change routes | `src/Resources/config/pimcore/routing.yml` |
| Add/change services | `src/Resources/config/services.yml` |
| Add/change admin tab behavior | `src/EventSubscriber/PimcoreAdminSubscriber.php` |
| Update install class/fieldcollection | `src/Resources/install/`, then re-run `pimcore:bundle:install` |
