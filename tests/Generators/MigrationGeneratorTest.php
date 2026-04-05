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
    foreach ([
        database_path('migrations/*_migration_projects_table.php'),
        database_path('migrations/*_to_migration_projects.php'),
        database_path('migrations/*_on_migration_projects.php'),
    ] as $pattern) {
        foreach (File::glob($pattern) as $migration) {
            File::delete($migration);
        }
    }

    DB::table('migrations')
        ->where(function ($query) {
            $query->where('migration', 'like', '%_migration_projects_table')
                ->orWhere('migration', 'like', '%_to_migration_projects')
                ->orWhere('migration', 'like', '%_on_migration_projects');
        })
        ->delete();

    DB::table('architect_blueprint_revisions')->delete();
    DB::table('architect_blueprints')->delete();

    if (Schema::hasTable('migration_projects')) {
        Schema::drop('migration_projects');
    }
});

it('generates a migration file with correct content', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
        ->toContain("Schema::create('migration_projects', function (Blueprint \$table) {")
        ->toContain("\$table->string('title');")
        ->toContain("\$table->text('description')->nullable();")
        ->toContain('$table->softDeletes();')
        ->toContain('$table->timestamps();');

    // Cleanup
    File::delete($path);
});

it('handles overwrite table logic', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'overwrite_table' => true,
        'columns' => [['name' => 'title', 'type' => 'string']],
    ]);

    $generator = new MigrationGenerator;
    $path = $generator->generate($blueprint);

    $content = File::get($path);

    expect($content)
        ->toContain("Schema::dropIfExists('migration_projects');");

    File::delete($path);
});

it('updates an existing pending create migration in place during merge mode', function () {
    $generator = new MigrationGenerator;

    $initialBlueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $path = $generator->generate($initialBlueprint);

    $updatedBlueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
        ->and(substr_count($content, "Schema::create('migration_projects'"))->toBe(1);
});

it('creates a sync migration for missing columns on an existing table', function () {
    $generator = new MigrationGenerator;

    $initialBlueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $createPath = $generator->generate($initialBlueprint);
    migrateMigrationGeneratorTestMigration($createPath);

    $updatedBlueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
        ->and(basename($syncPath))->toContain('_update_migration_projects_table.php')
        ->and(basename($syncPath))->not->toContain('_sync_')
        ->and($content)->toContain("Schema::table('migration_projects'")
        ->and($content)->toContain("\$table->text('summary');")
        ->and($content)->toContain('$table->softDeletes();');

    File::delete($syncPath);
});

it('creates a sync migration for nullable default and index changes', function () {
    $generator = new MigrationGenerator;

    $initialBlueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $initialPath = $generator->generate($initialBlueprint);
    migrateMigrationGeneratorTestMigration($initialPath);

    $updatedBlueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'is_nullable' => true, 'default' => 'draft', 'is_index' => true],
        ],
    ]);

    $syncPath = $generator->generate($updatedBlueprint);
    $content = File::get($syncPath);

    expect(basename($syncPath))->toContain('_update_column_title_on_migration_projects.php')
        ->and($content)
        ->toContain("\$table->string('title')->nullable()->default('draft')->change();")
        ->toContain("\$table->index('title');")
        ->toContain("\$table->dropIndex(['title']);");

    File::delete($syncPath);
});

it('creates a sync migration with a confirmed likely rename', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('legacy_name');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('legacy');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'text'],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('migration_projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('summary')->after('title');")
        ->toContain("\$table->dropColumn('summary');")
        ->not->toContain("Schema::create('migration_projects'");
});

it('falls back to the default preview for non-additive existing table changes', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'is_nullable' => true],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::create('migration_projects', function (Blueprint \$table) {")
        ->not->toContain("Schema::table('migration_projects', function (Blueprint \$table) {");
});

it('previews an additive sync migration for string keyed new columns on an existing table', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'generation_mode' => 'merge',
        'columns' => [
            'existing-title' => ['name' => 'title', 'type' => 'string'],
            'new-summary' => ['name' => 'summary', 'type' => 'text'],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('migration_projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('summary')->after('title');")
        ->toContain("\$table->dropColumn('summary');");
});

it('previews a sync migration from an imported legacy baseline before the first architect revision exists', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $generator = new MigrationGenerator;

    $preview = $generator->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'text', 'is_nullable' => true],
        ],
        'meta' => [
            'legacy_baseline' => [
                'table_name' => 'migration_projects',
                'model_name' => 'Project',
                'primary_key_type' => 'id',
                'soft_deletes' => false,
                'generation_mode' => 'merge',
                'columns' => [
                    ['name' => 'title', 'type' => 'string', 'is_nullable' => false, 'is_unique' => false, 'is_index' => false, 'default' => null],
                ],
            ],
        ],
    ]));

    expect($preview)
        ->toContain("Schema::table('migration_projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('title')->nullable()->change();")
        ->toContain("\$table->string('title')->default(null)->change();")
        ->not->toContain("Schema::create('migration_projects'");
});

it('prefers the latest generated blueprint revision when previewing additive updates', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'migration_projects',
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
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
        ],
    ]));

    $preview = (new MigrationGenerator)->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
        ->toContain("Schema::table('migration_projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('content')->after('slug');")
        ->toContain("\$table->boolean('is_published')->after('content');")
        ->toContain("\$table->dropColumn('is_published');")
        ->toContain("\$table->dropColumn('content');")
        ->not->toContain("\$table->string('slug')")
        ->not->toContain("\$table->dropColumn('slug');");
});

it('uses the latest revision for preview even when the live table is missing', function () {
    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'migration_projects',
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
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
        ],
    ]));

    $preview = (new MigrationGenerator)->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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
        ->toContain("Schema::table('migration_projects', function (Blueprint \$table) {")
        ->toContain("\$table->text('excerpt')->after('slug');")
        ->toContain("\$table->string('status')->after('excerpt');")
        ->not->toContain("Schema::create('migration_projects'")
        ->not->toContain("\$table->string('slug')->unique()");
});

it('generates a sync migration from the latest revision diff instead of stale database state', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'migration_projects',
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
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'excerpt', 'type' => 'text'],
            ['name' => 'status', 'type' => 'string'],
        ],
    ]));

    $syncPath = (new MigrationGenerator)->generate(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
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

    expect(basename($syncPath))->toContain('_add_columns_user_id_published_at_to_migration_projects.php')
        ->and($content)
        ->toContain("\$table->foreignId('user_id')")
        ->toContain("\$table->dateTime('published_at')->nullable()")
        ->not->toContain("\$table->text('excerpt')")
        ->not->toContain("\$table->string('status')")
        ->not->toContain("\$table->dropColumn('excerpt');")
        ->not->toContain("\$table->dropColumn('status');");

    File::delete($syncPath);
});

it('does not duplicate unique indexes when generating a sync migration for a new unique column', function () {
    Schema::create('migration_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $syncPath = (new MigrationGenerator)->generate(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_nullable' => true, 'is_unique' => true],
        ],
    ]));

    $content = File::get($syncPath);

    expect(substr_count($content, '->unique()'))->toBe(1)
        ->and($content)->toContain("\$table->string('slug')->nullable()->unique();")
        ->and($content)->not->toContain("\$table->unique('slug');");

    File::delete($syncPath);
});

it('does not duplicate unique indexes when previewing a new unique column from the latest revision diff', function () {
    $storedBlueprint = ArchitectBlueprint::create([
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'primary_key_type' => 'id',
        'columns' => [
            ['name' => 'title', 'type' => 'string', 'default' => null, 'is_nullable' => false, 'is_unique' => false, 'is_index' => false],
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
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]));

    $preview = (new MigrationGenerator)->preview(BlueprintData::fromArray([
        'table_name' => 'migration_projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_nullable' => true, 'is_unique' => true],
        ],
    ]));

    expect(substr_count($preview, '->unique()'))->toBe(1)
        ->and($preview)->toContain("\$table->string('slug')->nullable()->unique()->after('title');")
        ->and($preview)->not->toContain("\$table->unique('slug');");
});

function migrateMigrationGeneratorTestMigration(string $path): void
{
    Artisan::call('migrate', [
        '--path' => $path,
        '--realpath' => true,
    ]);
}
