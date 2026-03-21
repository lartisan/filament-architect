<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\Support\RegenerationPlanner;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

function recordProjectRevision(array $columns): ArchitectBlueprint
{
    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'primary_key_type' => 'id',
        'columns' => $columns,
        'soft_deletes' => false,
        'meta' => [
            'gen_factory' => true,
            'gen_seeder' => true,
            'gen_resource' => true,
            'generation_mode' => 'merge',
            'allow_destructive_changes' => false,
            'allow_likely_renames' => false,
        ],
    ]);

    $storedBlueprint->recordRevision(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => array_map(fn (array $column): array => array_filter([
            'name' => $column['name'],
            'type' => $column['type'],
            'is_nullable' => $column['is_nullable'] ?? null,
        ], fn ($value): bool => $value !== null), $columns),
    ]));

    return $storedBlueprint;
}

function projectBlueprint(array $columns): BlueprintData
{
    return BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => $columns,
    ]);
}

afterEach(function () {
    if (Schema::hasTable('projects')) {
        Schema::drop('projects');
    }

    DB::table('migrations')
        ->where('migration', 'like', '%_projects_table')
        ->delete();

    DB::table('architect_blueprint_revisions')->delete();
    DB::table('architect_blueprints')->delete();

    foreach (File::glob(database_path('migrations/*_projects_table.php')) as $migration) {
        File::delete($migration);
    }
});

it('planner: treats an existing foreign key column as matching a foreignId blueprint definition', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id');
        $table->timestamps();
    });

    $plan = app(RegenerationPlanner::class)->plan(projectBlueprint([
            ['name' => 'author_id', 'type' => 'foreignId', 'relationship_table' => 'users'],
    ]));

    expect($plan->hasSchemaChanges())->toBeFalse()
        ->and($plan->schemaOperations)->toHaveCount(1)
        ->and($plan->schemaOperations[0]->action)->toBe('noop');
});

it('planner: ignores column order-only changes for an existing related table schema', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->foreignId('author_id');
        $table->dateTime('published_at')->nullable();
        $table->timestamps();
    });

    $plan = app(RegenerationPlanner::class)->plan(projectBlueprint([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'published_at', 'type' => 'dateTime', 'is_nullable' => true],
            ['name' => 'author_id', 'type' => 'foreignId', 'relationship_table' => 'users'],
    ]));

    expect($plan->hasSchemaChanges())->toBeFalse()
        ->and($plan->schemaOperations[0]->action)->toBe('noop');
});

it('planner: reports content type changes without misclassifying unchanged relation columns', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id');
        $table->string('summary');
        $table->timestamps();
    });

    $plan = app(RegenerationPlanner::class)->plan(projectBlueprint([
            ['name' => 'author_id', 'type' => 'foreignId', 'relationship_table' => 'users'],
            ['name' => 'summary', 'type' => 'text', 'is_nullable' => true],
    ]));

    expect($plan->hasSchemaChanges())->toBeTrue()
        ->and($plan->toPreviewString())->toContain('Update summary: type string → text, make nullable')
        ->and($plan->toPreviewString())->not->toContain('Update author_id');
});

it('preview: keeps multiple new relational columns in blueprint order from the latest revision diff', function () {
    recordProjectRevision([
        ['name' => 'title', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
    ]);

    $preview = (new MigrationGenerator)->preview(projectBlueprint([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'author_id', 'type' => 'foreignId', 'relationship_table' => 'users'],
            ['name' => 'reviewer_id', 'type' => 'foreignId', 'relationship_table' => 'users', 'is_nullable' => true, 'is_index' => true],
            ['name' => 'slug', 'type' => 'string', 'is_nullable' => true, 'is_unique' => true],
    ]));

    expect($preview)
        ->toContain("\$table->foreignId('author_id')->after('title');")
        ->toContain("\$table->foreignId('reviewer_id')->nullable()->index()->after('author_id');")
        ->toContain("\$table->string('slug')->nullable()->unique()->after('reviewer_id');")
        ->not->toContain("\$table->index('reviewer_id');")
        ->not->toContain("\$table->unique('slug');");
});

it('preview: ignores column order-only changes for a related table from the latest revision diff', function () {
    recordProjectRevision([
            ['name' => 'title', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
            ['name' => 'author_id', 'type' => 'foreignId', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
            ['name' => 'published_at', 'type' => 'dateTime', 'default' => null, 'is_nullable' => true, 'is_unique' => false, 'is_index' => false],
    ]);

    $preview = (new MigrationGenerator)->preview(projectBlueprint([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'published_at', 'type' => 'dateTime', 'is_nullable' => true],
            ['name' => 'author_id', 'type' => 'foreignId'],
    ]));

    expect($preview)
        ->toContain('// No schema changes detected.')
        ->not->toContain('renameColumn')
        ->not->toContain('dropColumn');
});

it('sync migration: changes a content column type from string to text', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('summary');
        $table->timestamps();
    });

    $syncPath = (new MigrationGenerator)->generate(projectBlueprint([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'text', 'is_nullable' => true],
    ]));

    $content = File::get($syncPath);

    expect($content)
        ->toContain("\$table->text('summary')->nullable()->change();")
        ->toContain("\$table->string('summary')->default(null)->change();");

    File::delete($syncPath);
});

it('sync migration: makes an existing foreign key nullable without a false type diff', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id');
        $table->timestamps();
    });

    $syncPath = (new MigrationGenerator)->generate(projectBlueprint([
            ['name' => 'author_id', 'type' => 'foreignId', 'is_nullable' => true, 'relationship_table' => 'users'],
    ]));

    $content = File::get($syncPath);

    expect($content)
        ->toContain("\$table->foreignId('author_id')->nullable()->change();")
        ->not->toContain("unsignedBigInteger → foreignid")
        ->not->toContain("\$table->unsignedBigInteger('author_id')->change();");

    File::delete($syncPath);
});

