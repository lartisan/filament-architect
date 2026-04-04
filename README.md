# Filament Architect

<div align="center">

A powerful [Filament](https://filamentphp.com) plugin that enables rapid scaffolding and generation of Eloquent models, migrations, factories, seeders, and Filament resources through an intuitive wizard interface.

<div align="center" class="filament-hidden">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lartisan/filament-architect.svg)](https://packagist.org/packages/lartisan/filament-architect)
[![GitHub Tests](https://img.shields.io/github/actions/workflow/status/lartisan/filament-architect/tests.yml?label=Tests)](https://github.com/lartisan/filament-architect/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/lartisan/filament-architect.svg)](https://packagist.org/packages/lartisan/filament-architect)
[![License](https://img.shields.io/packagist/l/lartisan/filament-architect.svg)](https://packagist.org/packages/lartisan/filament-architect)

</div>
</div>

> [!IMPORTANT]
> `v1.0.0` introduces new database migrations for Filament Architect.
> If you are upgrading from `v0.1.5` or earlier, publish the latest package migrations and run:
>
> ```bash
> php artisan migrate
> ```
>
> See [`UPGRADE.md`](UPGRADE.md) for the full upgrade steps.

It gives you a wizard for defining a table schema, then generates and updates the matching:

- Eloquent model
- migration
- factory
- seeder
- Filament resource and page classes

The current implementation is built for iterative work, not just first-time scaffolding. Existing blueprints can be created, merged, or replaced depending on the selected generation mode.

## Quick Start

> Prerequisite: you already have a Laravel app with a Filament panel set up.

> Upgrading an existing install? Review [`UPGRADE.md`](UPGRADE.md) before opening Architect in your panel.

1. Install the package.
2. Run the Architect installer.
3. Register the plugin in your Filament panel.
4. Run migrations.
5. Open the **Architect** action in your panel and generate your first resource stack.

```bash
composer require lartisan/filament-architect
php artisan architect:install
php artisan migrate
```

```php
use Lartisan\Architect\ArchitectPlugin;

->plugins([
    ArchitectPlugin::make(),
])
```

---

## Highlights

### Wizard-driven scaffolding inside Filament
- Multi-step wizard with database, Eloquent, and review steps
- Live previews for:
  - migration
  - model
  - factory
  - seeder
  - Filament resource
- Existing blueprints can be listed, loaded back into the wizard, and deleted

### Generates the full stack around a model
Architect can generate:
- Eloquent models
- create migrations
- sync/update migrations for existing tables
- model factories
- seeders
- Filament resources
- Filament `List`, `Create`, `Edit`, and `View` pages

### Three generation modes
When files already exist, you can choose how Architect behaves:

- `create` — only create missing blueprints
- `merge` — refresh managed/generated sections while preserving custom code where possible
- `replace` — rewrite generated blueprints and unlock destructive rebuild workflows

`merge` is the default and the safest mode for day-to-day iteration.

### Merge-aware updates for existing code
The plugin now updates existing generated files instead of blindly overwriting them.

For models, factories, and Filament resources, the merge flow is parser-aware and uses `nikic/php-parser` to preserve custom code while refreshing generated structure. Seeders use a managed generated-region strategy.

Current merge support includes:

- **Model**
  - merges missing imports
  - merges traits like `HasFactory`, `SoftDeletes`, `HasUuids`, `HasUlids`
  - updates `$fillable`
  - adds inferred `belongsTo` relationships for foreign-key-like columns
  - preserves existing custom methods

- **Factory**
  - merges missing `definition()` keys
  - preserves existing custom values
  - preserves custom methods/state helpers

- **Seeder**
  - merges only the managed generated seeding region
  - preserves custom logic outside that region
  - hides managed markers from preview output

- **Filament Resource**
  - **v3 (monolithic):** merges generated `form()`, `table()`, and `infolist()` sections; preserves custom entries; keeps missing page classes
  - **v4 (domain):** merges each schema/table file independently (`*Form.php`, `*Infolist.php`, `*Table.php`); thin resource only syncs imports and `getEloquentQuery()`
  - removes stale managed fields when columns are removed from the blueprint
  - preserves existing page classes; creates missing ones

### Revision-aware migration previews and sync generation
Architect stores blueprint revisions after successful generation.

That revision history is used to make migration previews and sync migrations smarter:

- migration preview compares the current blueprint against the **latest generated revision**, not only the live database state
- sync migration generation follows the same revision-aware diff baseline
- this avoids re-showing or re-generating fields that belonged to a previous revision but were not yet applied in the database

### Safer schema evolution
Architect supports guarded schema changes for existing tables:

- additive sync migrations for new columns
- column type / nullable / default / index / unique changes
- likely rename detection
- destructive change gating for removed columns
- soft delete add/remove handling
- optional immediate migration execution
- automatic warning/defer behavior when risky schema operations are not explicitly allowed

### Better generated-file readability
When enabled, Architect will try to run a formatter after writing generated files.

It also normalizes merged output for several blueprint types so updated files stay readable, including:
- multiline resource arrays and fluent chains
- spacing between class members in models and factories
- multiline factory `definition()` arrays

### Filament panel integration
- Works with **Filament 3, 4 and 5**
- Generates resources in the correct structure for your Filament version (`v3` flat or `v4`/`v5` domain) — controlled by `ARCHITECT_FILAMENT_VERSION`
- Registers as a global panel action through the plugin
- Can render as a normal button or icon button
- Supports these render hooks:
  - `PanelsRenderHook::GLOBAL_SEARCH_BEFORE`
  - `PanelsRenderHook::GLOBAL_SEARCH_AFTER`
  - `PanelsRenderHook::USER_MENU_AFTER`

### Production-aware visibility
The Architect action is hidden in production by default unless explicitly enabled.

### Extension points for Premium / third-party packages
Architect Core now exposes a small set of stable extension points so premium modules or internal packages can build on the OSS workflow without forking the generators.

- **Capability resolver**
  - resolve named capabilities such as `premium.blocks` or `premium.revisions.browser`
  - defaults to a safe false-y resolver until another package defines a capability

- **Block registry**
  - merge additional block definitions into an existing block list while preserving base ordering
  - useful for premium-only content blocks or internal blueprint presets

- **UI extension registry**
  - register additional Architect tabs
  - append create/edit schema fragments
  - append existing-resource fragments

- **Post-generation hooks**
  - run logic after a blueprint is generated and its revision is recorded
  - useful for audit trails, premium revision tooling, or project-specific follow-up automation

- **Versioned revision snapshots**
  - blueprint revisions now expose a versioned snapshot payload plus metadata
  - gives future diff / restore tooling a stable contract to build on

Minimal access example:

```php
use Lartisan\Architect\ArchitectPlugin;

ArchitectPlugin::capabilities()->define('premium.blocks', true);

ArchitectPlugin::blocks()->register([
    'type' => 'premium-carousel',
    'label' => 'Premium Carousel',
]);

ArchitectPlugin::generationHooks()->afterGenerate(
    function ($blueprint, $blueprintData, $plan, bool $shouldRunMigration): void {
        // custom follow-up logic
    }
);
```

---

## Planned premium edition

Architect today is focused on strong open-source CRUD scaffolding and safe regeneration loops.

A future premium edition is planned to build on that foundation with workflows that are especially useful for larger teams, legacy projects, and more complex data models.

Planned premium areas currently include:

- **Visual revision history**
  - browse stored blueprint revisions
  - inspect snapshot diffs and generated changes visually

- **Rollback / restore workflows**
  - restore a previous blueprint revision
  - streamline reverting generated files and related schema changes

- **Legacy adoption / reverse engineering**
  - import existing models, tables, and resources into Architect
  - generate an editable blueprint from an existing Laravel project

- **Advanced relationship tooling**
  - many-to-many and pivot-table support
  - polymorphic relationship support
  - richer Filament relationship generation flows

- **Team-oriented workflows**
  - approvals and change review flows
  - blueprint locks, audit trails, and collaboration-oriented tooling

- **Priority support**
  - commercial support for teams that want faster feedback and help

> These features are planned / proposed, not shipped yet. The open-source package described in this README is the currently available product.

### Waiting list / early-bird pricing

Before the premium edition launches, a waiting list is planned.

The idea is to offer **early-bird launch pricing** to people who join that waiting list before release.

Until pricing and packaging are finalized, it is safest to describe this as:

- planned early-access / waiting-list announcement
- likely early-bird pricing for pre-launch signups
- final details to be confirmed at launch

[Join the waiting list](https://filamentcomponents.com/wailtlist/architect?source=github)
---

## Requirements

- PHP `^8.3`
- Filament `^4.0|^5.0`

---

## Installation

For a new installation:

```bash
composer require lartisan/filament-architect
php artisan architect:install
php artisan migrate
```

Then register the plugin in your Filament panel provider:

```php
<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Lartisan\Architect\ArchitectPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->plugins([
                ArchitectPlugin::make(),
            ]);
    }
}
```

If you are upgrading from `v0.1.5` or an earlier release, publish the latest package migrations before running `php artisan migrate`:

```bash
php artisan vendor:publish --tag=architect-migrations
php artisan migrate
```

The `architect:install` command currently:
- runs `filament:assets`
- publishes the package migrations
- adds `@php artisan filament:assets` to `composer.json` `post-autoload-dump` if needed
- displays an explicit reminder that `v1.0.0` requires `php artisan migrate`

This creates the Architect tables used for:
- saved blueprints
- blueprint revision history

For an upgrade-specific walkthrough, see [`UPGRADE.md`](UPGRADE.md).

---

## Add the plugin to a Filament panel

Register the plugin in your panel provider:

```php
<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Lartisan\Architect\ArchitectPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->plugins([
                ArchitectPlugin::make(),
            ]);
    }
}
```

Optional plugin customization:

```php
use Filament\View\PanelsRenderHook;
use Lartisan\Architect\ArchitectPlugin;

ArchitectPlugin::make()
    ->iconButton()
    ->renderHook(PanelsRenderHook::USER_MENU_AFTER);
```

---

## Configuration

Architect uses sensible runtime fallbacks, but the available options are defined in `config/architect.php`.

Key options include:

- `show`
- `generate_factory`
- `generate_seeder`
- `generate_resource`
- `default_generation_mode`
- `format_generated_files`
- `formatter`
- `models_namespace`
- `factories_namespace`
- `seeders_namespace`
- `resources_namespace`
- `filament_version`

Example configuration:

```php
return [
    'show' => env('ARCHITECT_SHOW', false),
    'generate_factory' => env('ARCHITECT_GENERATE_FACTORY', true),
    'generate_seeder' => env('ARCHITECT_GENERATE_SEEDER', true),
    'generate_resource' => env('ARCHITECT_GENERATE_RESOURCE', true),
    'default_generation_mode' => env('ARCHITECT_DEFAULT_GENERATION_MODE', 'merge'),
    'format_generated_files' => env('ARCHITECT_FORMAT_GENERATED_FILES', true),
    'formatter' => env('ARCHITECT_FORMATTER', 'pint_if_available'),
    'models_namespace' => env('ARCHITECT_MODELS_NAMESPACE', 'App\\Models'),
    'factories_namespace' => env('ARCHITECT_FACTORIES_NAMESPACE', 'Database\\Factories'),
    'seeders_namespace' => env('ARCHITECT_SEEDERS_NAMESPACE', 'Database\\Seeders'),
    'resources_namespace' => env('ARCHITECT_RESOURCES_NAMESPACE', 'App\\Filament\\Resources'),
    'filament_version' => env('ARCHITECT_FILAMENT_VERSION', 'v4'),
];
```

Useful environment variables:

```env
ARCHITECT_SHOW=true
ARCHITECT_GENERATE_FACTORY=true
ARCHITECT_GENERATE_SEEDER=true
ARCHITECT_GENERATE_RESOURCE=true
ARCHITECT_DEFAULT_GENERATION_MODE=merge
ARCHITECT_FORMAT_GENERATED_FILES=true
ARCHITECT_FORMATTER=pint_if_available
ARCHITECT_MODELS_NAMESPACE=App\Models
ARCHITECT_FACTORIES_NAMESPACE=Database\Factories
ARCHITECT_SEEDERS_NAMESPACE=Database\Seeders
ARCHITECT_RESOURCES_NAMESPACE=App\Filament\Resources
ARCHITECT_FILAMENT_VERSION=v4
```

### Filament version

Architect supports two resource output structures depending on your installed Filament version.

Set `ARCHITECT_FILAMENT_VERSION` in your `.env` file:

| Value | Target | Structure |
|-------|---------|-----------|
| `v4` (default) | Filament 4 / 5 | Domain folder per model (`Resources/Users/`) with separate `Schemas/` and `Tables/` sub-classes |
| `v3` | Filament 3 | Flat structure (`Resources/UserResource.php`) with inline form, table and infolist |

```env
# Filament 4 or 5 (default)
ARCHITECT_FILAMENT_VERSION=v4

# Filament 3
ARCHITECT_FILAMENT_VERSION=v3
```

### Formatting options
Supported formatter values:

- `pint_if_available` — run Pint only when a local binary exists
- `pint` — try to run Pint from the local project
- `none` — disable formatter execution

---

## Wizard workflow

### 1. Database step
You define the table structure:

- table name
- primary key type: `id`, `uuid`, or `ulid`
- soft deletes
- columns
- optional overwrite toggle in `replace` mode when the table already exists

Supported column types in the wizard:

- `string`
- `text`
- `integer`
- `unsignedBigInteger`
- `boolean`
- `json`
- `date`
- `dateTime`
- `foreignId`
- `foreignUuid`
- `foreignUlid`

Per-column options:
- default value
- nullable
- unique
- index
- drag-and-drop ordering

For foreign-key-like columns, you can also provide:
- optional related table metadata
- an optional relationship title column to improve generated Filament relationship fields

### 2. Eloquent step
You choose:

- model name
- generation mode
- whether to generate:
  - factory
  - seeder
  - Filament resource

### 3. Review step
You can:

- preview generated code
- choose whether to run migrations immediately
- allow likely renames
- allow destructive schema changes

Current code previews include:
- migration
- model
- factory
- seeder
- Filament resource

---

## Blueprint management

Architect stores blueprints in the database so you can iterate over time.

Current blueprint management features:
- list saved blueprints in the “Blueprints” tab
- load a blueprint back into the wizard
- save updated blueprint state when generating
- delete blueprints
- store blueprint revisions after successful generation

Blueprint revisions are used to improve migration preview and sync generation accuracy across multiple iterations.

> Current behavior: deleting a blueprint from the table is destructive. It also drops the related database table (if present), removes matching migration records, deletes generated files, and removes generated Filament resource pages.

---

## Generated blueprints

### Model
Location depends on `models_namespace`.

Generated behavior includes:
- `$fillable` from defined columns
- `HasFactory`
- `SoftDeletes` / `HasUuids` / `HasUlids` when applicable
- inferred `belongsTo` relationships for foreign-key-like columns

Relationship inference currently supports columns such as:
- `user_id`
- `author_uuid`
- `category_ulid`

### Migration
Architect can generate:
- create migrations for new tables
- sync migrations for existing tables
- revision-aware migration previews
- revision-aware sync generation for existing blueprints

### Factory
Location depends on `factories_namespace`.

Generated definitions are inferred from column names and types, including special handling for:
- email-like fields
- passwords/secrets
- content/body/description fields
- dates, booleans, numeric fields
- foreign-key-like columns (model factory references)

### Seeder
Location depends on `seeders_namespace`.

Generated seeders use a managed region strategy so repeated generations can refresh the generated seeding block without wiping custom logic outside it.

### Filament Resource
Location depends on `resources_namespace` and `filament_version`.

Generated resource support includes:
- resource class
- list page
- create page
- edit page
- view page
- form schema generation
- table column generation
- infolist generation
- soft delete filters and bulk actions
- generated relationship fields for foreign-key-like columns
- optional relationship title-column metadata for generated Filament relationship selects and table columns

#### v4 / v5 — domain structure (default)

Architect generates a **thin resource** that delegates to dedicated schema and table classes.

```
app/Filament/Resources/
└── Users/                          # domain folder = Str::pluralStudly(model)
    ├── UserResource.php             # thin — delegates to Form / Infolist / Table classes
    ├── Pages/
    │   ├── CreateUser.php
    │   ├── EditUser.php
    │   ├── ListUsers.php
    │   └── ViewUser.php
    ├── Schemas/
    │   ├── UserForm.php             # form()->components([...])
    │   └── UserInfolist.php         # infolist()->components([...])
    └── Tables/
        └── UsersTable.php           # table()->columns([...])
```

In `merge` mode each file is updated independently: form fields merge into `UserForm.php`, table columns into `UsersTable.php`, and the thin `UserResource.php` only receives new imports and the `getEloquentQuery()` method if needed.

#### v3 — flat / monolithic structure

Architect generates a single **monolithic resource** with form, infolist, and table defined inline, matching the classic Filament v3 layout.

```
app/Filament/Resources/
├── UserResource.php                 # form(), infolist(), table() all inline
└── UserResource/
    └── Pages/
        ├── CreateUser.php
        ├── EditUser.php
        ├── ListUsers.php
        └── ViewUser.php
```

To use v3 output, add `ARCHITECT_FILAMENT_VERSION=v3` to your `.env`.

---

## Schema safety and migration behavior

When working against existing tables, Architect supports a safer regeneration workflow.

### Supported diff types
- add columns
- change column type / nullable / default
- add or remove index / unique state
- detect likely renames
- remove columns when explicitly allowed
- add/remove soft deletes

### Safety controls
- **Likely renames** are opt-in
- **Destructive changes** are opt-in
- immediate migration execution is blocked when deferred risky operations are still present
- warning notifications are shown when migration execution is deferred for safety

### Revision-aware behavior
If a blueprint has prior revisions, Architect compares against the latest generated revision first.

This means:
- preview shows only the newest diff
- generated sync migrations also use that latest revision diff
- stale database state does not cause already-generated fields to be re-added in previews or sync migrations

---

## Merge behavior summary

| Blueprint | Managed / generated updates in `merge` mode | Preserved in `merge` mode |
| --- | --- | --- |
| Model | Missing imports, framework traits, `$fillable`, inferred `belongsTo` relationships | Existing custom methods and existing relationship overrides |
| Factory | Missing `definition()` keys and generated imports | Existing custom field values and custom state/helper methods |
| Seeder | Managed generated block inside `run()` | Custom logic outside the managed seed region |
| Filament Resource (v3) | Managed `form()`, `table()`, `infolist()`, generated filters / bulk actions, missing page wiring | Clearly custom unmatched entries where possible, existing page classes |
| Filament Resource (v4) | Thin resource: new imports + `getEloquentQuery()` if missing; `*Form.php`, `*Infolist.php`, `*Table.php` each merged independently | Custom components in each schema/table file; custom page classes |
| Resource Pages | Missing generated page classes | Existing page classes and their custom logic |
| Migration Preview / Sync | Revision-aware diffing from the latest stored blueprint revision | Previous revisions stay as the baseline instead of being re-added from stale DB state |

### Notes

- `merge` mode is intended to refresh generated structure without flattening the whole file.
- Destructive schema operations and likely renames are still gated by explicit confirmation.
- `replace` mode is the option to use when you intentionally want Architect to rewrite generated blueprints.

---

## Plugin visibility and rendering

Architect is hidden in production by default.

To explicitly enable it:

```env
ARCHITECT_SHOW=true
```

Render hook and icon button options are configured through `ArchitectPlugin`:

```php
ArchitectPlugin::make()
    ->iconButton(true)
    ->renderHook(\Filament\View\PanelsRenderHook::GLOBAL_SEARCH_BEFORE);
```

---

#### Action Color

Customize the color of the Architect action button or icon button:

```php
ArchitectPlugin::make()
    ->actionColor('success')
```

When no custom color is provided, the action keeps Filament's default `primary` color.

#### Custom Render Hook

Change where the Architect action is rendered in your panel:

```php
use Filament\View\PanelsRenderHook;

ArchitectPlugin::make()
    ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE)
```

By default, the action is rendered at `PanelsRenderHook::GLOBAL_SEARCH_BEFORE` when the panel topbar is enabled, and at `PanelsRenderHook::SIDEBAR_NAV_END` when the panel uses `->topbar(false)`.

Available render hooks:
- `PanelsRenderHook::GLOBAL_SEARCH_BEFORE` (default when the topbar is enabled)
- `PanelsRenderHook::GLOBAL_SEARCH_AFTER`
- `PanelsRenderHook::USER_MENU_AFTER`
- `PanelsRenderHook::SIDEBAR_NAV_START`
- `PanelsRenderHook::SIDEBAR_NAV_END` (default when the topbar is hidden)
- `PanelsRenderHook::SIDEBAR_FOOTER`

## Usage

### Accessing the Wizard

Once installed and configured, the Architect plugin adds an action button to your Filament panel. Click the "Architect" button to open the generation wizard.

### Step 1: Database Configuration

Define your database table structure:

- **Table Name**: The name of your database table
- **Model Name**: The name of your Eloquent model class
- **Primary Key Type**: Choose between `id` (default), `uuid`, or `ulid`
- **Soft Deletes**: Enable soft delete support for your model

Configure what to generate:

- **Columns**: Define table columns with:
  - Column name
  - Data type (string, integer, boolean, datetime, text, etc.)
  - Nullable option
  - Default values
  - Indexing options

### Step 2: Eloquent Configuration

- **Model Name**: Automatically generated from table name (e.g., `projects` → `Project`)

- **Generation Options** (configurable via `config/architect.php`):
  - `gen_factory`: Generate model factory (default: true)
  - `gen_seeder`: Generate database seeder (default: true)
  - `gen_resource`: Generate Filament resource with CRUD pages (default: true)

**Note**: Migrations and Models are always generated as they are core to the plugin's functionality.

### Step 3: Review & Generate

Review your configuration and click "Save & Generate" to:

1. Save the blueprint to the database
2. Generate all selected files
3. Optionally run migrations immediately
4. Create Filament resource pages (list, create, edit, view)

### Managing Blueprints

In the "Blueprints" tab, you can:

- **View** all previously created blueprints
- **Edit** and regenerate any blueprint (coming soon)
- **Delete** blueprints

## Generated Files

When you use the Architect wizard, it generates the following files:

### Model
- Location: `app/Models/{ModelName}.php`
- Includes configured columns and relationships

### Migration
- Location: `database/migrations/{timestamp}_create_{table_name}_table.php`
- Creates table with all specified columns

### Factory
- Location: `database/factories/{ModelName}Factory.php`
- Includes factory definitions for all columns

### Seeder
- Location: `database/seeders/{ModelName}Seeder.php`
- Seedable template with model factory integration

### Filament Resource

Output structure depends on `ARCHITECT_FILAMENT_VERSION` (default: `v4`).

**v4 / v5 — domain structure:**
- **Resource class**: `app/Filament/Resources/{Models}/` (e.g. `Resources/Users/UserResource.php`)
- **Form schema**: `Schemas/{Model}Form.php`
- **Infolist schema**: `Schemas/{Model}Infolist.php`
- **Table**: `Tables/{Models}Table.php`
- **Pages**: `Pages/List{Models}.php`, `Create{Model}.php`, `Edit{Model}.php`, `View{Model}.php`

**v3 — flat / monolithic:**
- **Resource class**: `app/Filament/Resources/{Model}Resource.php`
- **Pages**: `{Model}Resource/Pages/List{Models}.php`, etc.


## Development

Run tests:

```bash
composer test
```

Format code:

```bash
composer format
```

Lint with Pint:

```bash
composer lint
```

---

## Current scope

Architect currently focuses on fast CRUD scaffolding and safe regeneration loops for Laravel + Filament projects.

The strongest supported workflows today are:
- initial scaffolding of a resource stack
- repeated blueprint-driven updates in `merge` mode
- revision-aware migration preview and sync generation
- preserving hand-written custom code around managed generated sections

Planned premium work is aimed at visual revision tooling, rollback workflows, legacy-project adoption, advanced relationships, and team-oriented collaboration features.

---

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

