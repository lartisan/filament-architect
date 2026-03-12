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

    public static function resource(string $resourceName): string
    {
        return static::pathForNamespace((string) config('architect.resources_namespace', 'App\\Filament\\Resources'), $resourceName);
    }

    public static function resourceDirectory(string $resourceName): string
    {
        return Str::beforeLast(static::resource($resourceName), '.php');
    }

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
