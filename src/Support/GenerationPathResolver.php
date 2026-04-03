<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Str;

class GenerationPathResolver
{
    public static function model(string $modelName): string
    {
        return static::pathForNamespace(static::modelsNamespace(), $modelName);
    }

    public static function factory(string $factoryName): string
    {
        return static::pathForNamespace((string) config('architect.factories_namespace', 'Database\\Factories'), $factoryName);
    }

    public static function seeder(string $seederName): string
    {
        return static::pathForNamespace(static::seedersNamespace(), $seederName);
    }

    // -------------------------------------------------------------------------
    // Version detection
    // -------------------------------------------------------------------------

    public static function filamentVersion(): string
    {
        return (string) config('architect.filament_version', 'v4');
    }

    public static function isFilamentV4(): bool
    {
        return static::filamentVersion() === 'v4';
    }

    // -------------------------------------------------------------------------
    // Resource paths — version-aware
    // -------------------------------------------------------------------------

    /**
     * Absolute path to the main resource file.
     *
     * v3: app/Filament/Resources/UserResource.php
     * v4: app/Filament/Resources/Users/UserResource.php
     */
    public static function resource(string $resourceName): string
    {
        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');

        if (static::isFilamentV4()) {
            $modelName = Str::beforeLast($resourceName, 'Resource');

            return static::pathForNamespace(
                $baseNamespace.'\\'.Str::pluralStudly($modelName),
                $resourceName
            );
        }

        return static::pathForNamespace($baseNamespace, $resourceName);
    }

    /**
     * Absolute path to the resource domain directory.
     *
     * v3: app/Filament/Resources/UserResource   (parent dir for Pages/)
     * v4: app/Filament/Resources/Users           (domain folder for Pages/, Schemas/, Tables/)
     */
    public static function resourceDirectory(string $resourceName): string
    {
        if (static::isFilamentV4()) {
            return dirname(static::resource($resourceName));
        }

        return Str::beforeLast(static::resource($resourceName), '.php');
    }

    // -------------------------------------------------------------------------
    // Namespace helpers (primarily used by v4)
    // -------------------------------------------------------------------------

    /**
     * Domain namespace for the resource folder.
     * e.g. App\Filament\Resources\Users
     */
    public static function resourceNamespace(string $modelName): string
    {
        $baseNamespace = (string) config('architect.resources_namespace', 'App\\Filament\\Resources');

        return $baseNamespace.'\\'.Str::pluralStudly($modelName);
    }

    /**
     * Namespace for the Schemas/ sub-folder.
     * e.g. App\Filament\Resources\Users\Schemas
     */
    public static function resourceSchemasNamespace(string $modelName): string
    {
        return static::resourceNamespace($modelName).'\\Schemas';
    }

    /**
     * Namespace for the Tables/ sub-folder.
     * e.g. App\Filament\Resources\Users\Tables
     */
    public static function resourceTablesNamespace(string $modelName): string
    {
        return static::resourceNamespace($modelName).'\\Tables';
    }

    /**
     * Namespace for the Pages/ sub-folder.
     * e.g. App\Filament\Resources\Users\Pages
     */
    public static function resourcePagesNamespace(string $modelName): string
    {
        return static::resourceNamespace($modelName).'\\Pages';
    }

    // -------------------------------------------------------------------------
    // Concrete file paths for v4 schema and table classes
    // -------------------------------------------------------------------------

    /**
     * Absolute path to a schema class file.
     *
     * @param  string  $suffix  'Form' or 'Infolist'
     *
     * e.g. app/Filament/Resources/Users/Schemas/UserForm.php
     */
    public static function resourceSchemaFile(string $modelName, string $suffix): string
    {
        return static::pathForNamespace(
            static::resourceSchemasNamespace($modelName),
            $modelName.$suffix
        );
    }

    /**
     * Absolute path to the table class file.
     * e.g. app/Filament/Resources/Users/Tables/UsersTable.php
     */
    public static function resourceTableFile(string $modelName): string
    {
        return static::pathForNamespace(
            static::resourceTablesNamespace($modelName),
            Str::pluralStudly($modelName).'Table'
        );
    }

    // -------------------------------------------------------------------------
    // Shared namespace/path helpers
    // -------------------------------------------------------------------------

    public static function modelsNamespace(): string
    {
        return (string) config('architect.models_namespace', config('architect.namespace', 'App\\Models'));
    }

    public static function seedersNamespace(): string
    {
        return (string) config('architect.seeders_namespace', config('architect.seeder_namespace', 'Database\\Seeders'));
    }

    public static function pathForNamespace(string $namespace, string $className): string
    {
        $relativeNamespacePath = str_replace('\\', '/', trim($namespace, '\\'));

        if (Str::startsWith($namespace, 'App\\')) {
            $relativePath = Str::after($relativeNamespacePath, 'App/');

            return app_path(trim($relativePath.'/'.$className.'.php', '/'));
        }

        if (Str::startsWith($namespace, 'Database\\')) {
            $relativePath = Str::after($relativeNamespacePath, 'Database/');

            return database_path(trim($relativePath.'/'.$className.'.php', '/'));
        }

        return base_path(trim($relativeNamespacePath.'/'.$className.'.php', '/'));
    }
}
