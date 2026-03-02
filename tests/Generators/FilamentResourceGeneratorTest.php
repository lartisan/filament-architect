<?php

namespace Lartisan\Architect\Tests\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

it('generates a filament resource and pages', function () {
    config()->set('architect.resources_namespace', 'App\\Filament\\Resources');

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'gen_resource' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('class ProjectResource extends Resource')
        ->toContain('Forms\Components\TextInput::make(\'title\')')
        ->toContain('Tables\Columns\TextColumn::make(\'title\')');

    // Check pages
    $resourceDir = app_path('Filament/Resources/ProjectResource');
    expect(File::exists("$resourceDir/Pages/ListProjects.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/CreateProject.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/EditProject.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/ViewProject.php"))->toBeTrue();

    // Cleanup
    File::deleteDirectory(app_path('Filament'));
});

it('generates a filament resource with soft deletes', function () {
    config()->set('architect.resources_namespace', 'App\\Filament\\Resources');

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'gen_resource' => true,
        'soft_deletes' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);

    $content = File::get($path);

    expect($content)
        ->toContain('use Illuminate\Database\Eloquent\SoftDeletingScope;')
        ->toContain('Tables\Filters\TrashedFilter::make()')
        ->toContain('\Filament\Actions\ForceDeleteBulkAction::make()')
        ->toContain('\Filament\Actions\RestoreBulkAction::make()')
        ->toContain('public static function getEloquentQuery(): Builder');

    File::deleteDirectory(app_path('Filament'));
});

it('generates proper select components for all foreign key types', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'user_id', 'type' => 'foreignId', 'is_nullable' => false],
            ['name' => 'author_uuid', 'type' => 'foreignUuid', 'is_index' => true],
            ['name' => 'category_ulid', 'type' => 'foreignUlid', 'is_unique' => true],
        ],
        'gen_resource' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    // Test standard _id
    expect($content)->toContain("Forms\Components\Select::make('user_id')")
        ->toContain("->relationship('user', 'name')")
        ->toContain('->required()')
        ->and($content)->toContain("Forms\Components\Select::make('author_uuid')")
        ->toContain("->relationship('author', 'name')")
        ->toContain('->searchable()')
        ->and($content)->toContain("Forms\Components\Select::make('category_ulid')")
        ->toContain("->relationship('category', 'name')")
        ->toContain('->unique(ignoreRecord: true)');

    // Test _uuid with index (searchable)

    // Test _ulid with unique

    File::deleteDirectory(app_path('Filament'));
});

it('generates relationship columns in table with dot notation', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'author_uuid', 'type' => 'foreignUuid'],
        ],
        'gen_resource' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    // Verify that TextColumn uses author.name, not author_uuid
    expect($content)
        ->toContain("Tables\Columns\TextColumn::make('author.name')")
        ->toContain("->label('Author')")
        ->toContain('->sortable()')
        ->toContain('->searchable()');

    File::deleteDirectory(app_path('Filament'));
});
