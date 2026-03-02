# Filament Architect

<div align="center">

A powerful [Filament](https://filamentphp.com) plugin that enables rapid scaffolding and generation of Eloquent models, migrations, factories, seeders, and Filament resources through an intuitive wizard interface.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lartisan/filament-architect.svg)](https://packagist.org/packages/lartisan/filament-architect)
[![GitHub Tests](https://img.shields.io/github/actions/workflow/status/lartisan/filament-architect/tests.yml?label=Tests)](https://github.com/lartisan/filament-architect/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/lartisan/filament-architect.svg)](https://packagist.org/packages/lartisan/filament-architect)
[![License](https://img.shields.io/packagist/l/lartisan/filament-architect.svg)](https://packagist.org/packages/lartisan/filament-architect)

</div>

## Features

✨ **Interactive Wizard Interface** - A beautiful, user-friendly step-by-step wizard for defining your database schema

🗄️ **Auto-Generate Resources** - Automatically create:
- Eloquent Models
- Database Migrations
- Model Factories
- Database Seeders
- Filament Resources (Create, Read, Update, Delete pages)

⚙️ **Smart Configuration** - Define your schema with visual tools including:
- Column definitions with type validation
- Primary key customization
- Soft delete support
- Relationship management (coming soon)

💾 **Blueprint Management** - Save, edit, and regenerate your resource definitions
- View all created blueprints
- Update existing schemas
- Regenerate files without losing configuration

🎨 **Seamless Filament Integration** - Works perfectly with Filament v5.0+
- Renders as a global action in your Filament panel
- Configurable render hooks
- Icon button or full button display options

🔧 **Highly Configurable** - Customize namespaces, output paths, and generation behavior through configuration files

## Requirements

- PHP >= 8.3
- Laravel >= 11.0
- Filament >= 5.0
- Composer

## Installation

You can install the package via composer:

```bash
composer require lartisan/filament-architect
```

After installation, the package will automatically publish its assets and migrations.

### Publish Configuration

To publish the configuration file, run:

```bash
php artisan vendor:publish --tag=architect-config
```

### Run Migrations

Execute the migrations to create the `architect_blueprints` table:

```bash
php artisan migrate
```

## Configuration

### Basic Setup

Add the plugin to your Filament panel in your `PanelProvider`:

```php
<?php

namespace App\Filament\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Lartisan\Architect\ArchitectPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // ... other configuration
            ->plugins([
                ArchitectPlugin::make(),
            ]);
    }
}
```

### Configuration File

Edit `config/architect.php` to customize the namespaces and output paths:

```php
<?php

return [
    // Namespace for generated models
    'namespace' => 'App\\Models',

    // Namespace for generated factories
    'factories_namespace' => 'Database\\Factories',

    // Namespace for generated seeders
    'seeders_namespace' => 'Database\\Seeders',

    // Namespace for generated Filament resources
    'resources_namespace' => 'App\\Filament\\Resources',
];
```

### Plugin Options

#### Icon Button

Display Architect as an icon button instead of a full button:

```php
ArchitectPlugin::make()
    ->iconButton(true)
```

#### Custom Render Hook

Change where the Architect action is rendered in your panel:

```php
use Filament\View\PanelsRenderHook;

ArchitectPlugin::make()
    ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE)
```

Available render hooks:
- `PanelsRenderHook::GLOBAL_SEARCH_BEFORE` (default)
- `PanelsRenderHook::GLOBAL_SEARCH_AFTER`
- `PanelsRenderHook::USER_MENU_AFTER`
- And other Filament render hooks

## Usage

### Accessing the Wizard

Once installed and configured, the Architect plugin adds an action button to your Filament panel. Click the "Architect" button to open the generation wizard.

### Step 1: Database Configuration

Define your database table structure:

- **Table Name**: The name of your database table
- **Model Name**: The name of your Eloquent model class
- **Primary Key Type**: Choose between `id` (default), `uuid`, or `ulid`
- **Soft Deletes**: Enable soft delete support for your model

### Step 2: Eloquent Configuration

Configure what to generate:

- **Columns**: Define table columns with:
  - Column name
  - Data type (string, integer, boolean, datetime, text, etc.)
  - Nullable option
  - Default values
  - Indexing options

- **Generation Options**:
  - `gen_migration`: Generate database migration
  - `gen_factory`: Generate model factory
  - `gen_seeder`: Generate database seeder
  - `gen_resource`: Generate Filament resource with CRUD pages

### Step 3: Review & Generate

Review your configuration and click "Save & Generate" to:

1. Save the blueprint to the database
2. Generate all selected files
3. Optionally run migrations immediately
4. Create Filament resource pages (list, create, edit, view)

### Managing Blueprints

In the "Existing Resources" tab, you can:

- **View** all previously created blueprints
- **Edit** and regenerate any blueprint
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
- **Resource Class**: `app/Filament/Resources/{ModelName}Resource.php`
- **List Page**: Displays all records in a table
- **Create Page**: Form for creating new records
- **Edit Page**: Form for editing existing records
- **View Page**: Read-only view of a record

## Development

### Running Tests

To run the test suite:

```bash
composer test
```

### Code Quality

Format code using Pint:

```bash
composer format
```

Check code style with Pint:

```bash
composer lint
```

## Architecture

The plugin is organized into several key components:

### Generators
Located in `src/Generators/`, each generator handles creating specific files:

- `ModelGenerator` - Generates Eloquent models
- `MigrationGenerator` - Creates database migrations
- `FactoryGenerator` - Generates model factories
- `SeederGenerator` - Creates database seeders
- `FilamentResourceGenerator` - Generates Filament resources with all pages

### Livewire Components
- `ArchitectWizard` - Main wizard component with form handling
- `BlueprintsTable` - Table for managing existing blueprints

### Value Objects
- `BlueprintData` - Type-safe blueprint configuration
- `ColumnDefinition` - Type-safe column configuration

### Support
- `SchemaValidator` - Validates blueprint schemas before generation

## Advanced Usage

### Programmatic Generation

You can generate resources programmatically without using the wizard:

```php
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\Generators\ModelGenerator;

$blueprintData = BlueprintData::fromArray([
    'table_name' => 'posts',
    'model_name' => 'Post',
    'primary_key_type' => 'id',
    'columns' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'content', 'type' => 'text'],
    ],
    'soft_deletes' => false,
    'gen_factory' => true,
    'gen_seeder' => true,
    'gen_resource' => true,
]);

// Generate individual components
$modelGenerator = new ModelGenerator();
$modelGenerator->generate($blueprintData);
```

### Custom Stubs

Customize the generated files by publishing stubs:

```bash
php artisan vendor:publish --tag=architect-stubs
```

Edit the stubs in `stubs/` to match your project's conventions.

## Troubleshooting

### Migration not running
Ensure the `architect_blueprints` table has been created:
```bash
php artisan migrate
```

### Files not generating
- Check that the output directories exist and are writable
- Verify the configured namespaces match your project structure
- Check Laravel logs for detailed error messages

### Permission errors
Ensure your Laravel application has write permissions to the `app/`, `database/`, and `app/Filament/Resources/` directories.

## Performance Considerations

- Blueprint data is cached in the database for quick access
- Generation happens synchronously during wizard submission
- For large projects, consider running expensive operations in queued jobs

## Roadmap

- [ ] Relationship management (belongsTo, hasMany, belongsToMany)
- [ ] Custom field types and validation rules
- [ ] Import/export blueprints
- [ ] Batch generation
- [ ] Artisan command support
- [ ] API-based generation
- [ ] Integration with existing models

## Security Considerations

- Always validate user input from the wizard
- The plugin generates code based on user-defined specifications
- Review generated migrations before running them in production
- Use the plugin only in development environments or with proper authorization

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

Please ensure:
- Tests pass: `composer test`
- Code is formatted: `composer format`
- Code quality is maintained: `composer lint`

## Support

For issues, questions, or feature requests, please open an issue on [GitHub](https://github.com/lartisan/filament-architect/issues).

For Filament-specific questions, visit the [Filament Discord Community](https://discord.gg/filament).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for all notable changes.

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE) for more information.

---

## Credits

**Filament Architect** is developed and maintained by [lartisan](https://github.com/lartisan).

Special thanks to:
- [Filament](https://filamentphp.com) for the amazing admin panel framework
- [Laravel](https://laravel.com) for the excellent PHP framework
- The Laravel and Filament communities for their feedback and contributions

## Made with ❤️ by the Laravel Community

