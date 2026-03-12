<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    foreach (File::glob(database_path('migrations/*_projects_table.php')) as $migration) {
        File::delete($migration);
    }

    DB::table('migrations')
        ->where('migration', 'like', '%_projects_table')
        ->delete();

    DB::table('architect_blueprint_revisions')->delete();
    DB::table('architect_blueprints')->delete();

    if (Schema::hasTable('projects')) {
        Schema::drop('projects');
    }
});

it('generates a migration file with correct content', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'description', 'type' => 'text', 'is_nullable' => true],
        ],
        'soft_deletes' => true,
    ]);

    $generator = new MigrationGenerator;
    $path = $generator->generate($blueprint);

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain("Schema::create('projects', function (Blueprint \$table) {")
        ->toContain("\$table->string('title');")
        ->toContain("\$table->text('description')->nullable();")
        ->toContain('$table->softDeletes();')
        ->toContain('$table->timestamps();');

    // Cleanup
    File::delete($path);
});

it('handles overwrite table logic', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'overwrite_table' => true,
        'columns' => [['name' => 'title', 'type' => 'string']],
    ]);

    $generator = new MigrationGenerator;
    $path = $generator->generate($blueprint);

    $content = File::get($path);

    expect($content)
        ->toContain("Schema::dropIfExists('projects');");

    File::delete($path);
});

it('updates an existing pending create migration in place during merge mode', function () {
    $generator = new MigrationGenerator;

    $initialBlueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $path = $generator->generate($initialBlueprint);

    $updatedBlueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'text'],
        ],
    ]);

    $updatedPath = $generator->generate($updatedBlueprint);
    $content = File::get($updatedPath);

    expect($updatedPath)->toBe($path)
        ->and($content)->toContain("\$table->text('summary');")
        ->and(substr_count($content, "Schema::create('projects'"))->toBe(1);
});

it('creates a sync migration for missing columns on an existing table', function () {
    $generator = new MigrationGenerator;

    $initialBlueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $createPath = $generator->generate($initialBlueprint);
    Artisan::call('migrate', ['--path' => 'database/migrations']);

    $updatedBlueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'soft_deletes' => true,
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'text'],
        ],
    ]);

    $syncPath = $generator->generate($updatedBlueprint);
    $content = File::get($syncPath);

    expect($syncPath)->not->toBe($createPath)
        ->and(basename($syncPath))->toContain('_sync_projects_table.php')
        ->and($content)->toContain("Schema::table('projects'")
        ->and($content)->toContain("\$table->text('summary');")
        ->and($content)->toContain('$table->softDeletes();');

    File::delete($syncPath);
});

it('creates a sync migration for nullable default and index changes', function () {
    $generator = new MigrationGenerator;

    $initialBlueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $generator->generate($initialBlueprint);
    Artisan::call('migrate', ['--path' => 'database/migrations']);

    $updatedBlueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'is_nullable' => true, 'default' => 'draft', 'is_index' => true],
        ],
    ]);

    $syncPath = $generator->generate($updatedBlueprint);
    $content = File::get($syncPath);

    expect($content)
        ->toContain("\$table->string('title')->nullable()->default('draft')->change();")
        ->toContain("\$table->index('title');")
        ->toContain("\$table->dropIndex(['title']);");

    File::delete($syncPath);
});

it('creates a sync migration with a confirmed likely rename', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('legacy_name');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'allow_likely_renames' => true,
        'columns' => [
            ['name' => 'display_name', 'type' => 'string'],
        ],
    ]);

    $syncPath = $generator->generate($blueprint);
    $content = File::get($syncPath);

    expect($content)
        ->toContain("\$table->renameColumn('legacy_name', 'display_name');")
        ->and($content)->not->toContain("\$table->string('display_name');");

    File::delete($syncPath);
});

it('creates a sync migration with confirmed destructive column removals', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('legacy');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'allow_destructive_changes' => true,
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $syncPath = $generator->generate($blueprint);
    $content = File::get($syncPath);

    expect($content)
        ->toContain("\$table->dropColumn('legacy');")
        ->toContain("\$table->string('legacy');");

    File::delete($syncPath);
});

it('previews an additive sync migration for new columns on an existing table', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'text'],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('summary')->after('title');")
        ->toContain("\$table->dropColumn('summary');")
        ->not->toContain("Schema::create('projects'");
});

it('falls back to the default preview for non-additive existing table changes', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'is_nullable' => true],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::create('projects', function (Blueprint \$table) {")
        ->not->toContain("Schema::table('projects', function (Blueprint \$table) {");
});

it('previews an additive sync migration for string keyed new columns on an existing table', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'projects',
        'generation_mode' => 'merge',
        'columns' => [
            'existing-title' => ['name' => 'title', 'type' => 'string'],
            'new-summary' => ['name' => 'summary', 'type' => 'text'],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('summary')->after('title');")
        ->toContain("\$table->dropColumn('summary');");
});

it('prefers the latest generated blueprint revision when previewing additive updates', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'primary_key_type' => 'id',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
            ['name' => 'slug', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => true, 'is_index' => false],
        ],
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
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
        ],
    ]));

    $preview = (new MigrationGenerator)->preview(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
            ['name' => 'content', 'type' => 'text'],
            ['name' => 'is_published', 'type' => 'boolean'],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('content')->after('slug');")
        ->toContain("\$table->boolean('is_published')->after('content');")
        ->toContain("\$table->dropColumn('is_published');")
        ->toContain("\$table->dropColumn('content');")
        ->not->toContain("\$table->string('slug')")
        ->not->toContain("\$table->dropColumn('slug');");
});

it('uses the latest revision for preview even when the live table is missing', function () {
    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'primary_key_type' => 'id',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
            ['name' => 'slug', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => true, 'is_index' => false],
        ],
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
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
        ],
    ]));

    $preview = (new MigrationGenerator)->preview(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
            ['name' => 'excerpt', 'type' => 'text'],
            ['name' => 'status', 'type' => 'string'],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('excerpt')->after('slug');")
        ->toContain("\$table->string('status')->after('excerpt');")
        ->not->toContain("Schema::create('projects'")
        ->not->toContain("\$table->string('slug')->unique()");
});

it('generates a sync migration from the latest revision diff instead of stale database state', function () {
    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'primary_key_type' => 'id',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
            ['name' => 'excerpt', 'type' => 'text', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
            ['name' => 'status', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
        ],
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
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'excerpt', 'type' => 'text'],
            ['name' => 'status', 'type' => 'string'],
        ],
    ]));

    $syncPath = (new MigrationGenerator)->generate(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'excerpt', 'type' => 'text'],
            ['name' => 'status', 'type' => 'string'],
            ['name' => 'user_id', 'type' => 'foreignId'],
            ['name' => 'published_at', 'type' => 'dateTime', 'is_nullable' => true],
        ],
    ]));

    $content = File::get($syncPath);

    expect($content)
        ->toContain("\$table->foreignId('user_id')")
        ->toContain("\$table->dateTime('published_at')->nullable()")
        ->not->toContain("\$table->text('excerpt')")
        ->not->toContain("\$table->string('status')")
        ->not->toContain("\$table->dropColumn('excerpt');")
        ->not->toContain("\$table->dropColumn('status');");

    File::delete($syncPath);
});
