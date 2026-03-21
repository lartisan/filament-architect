<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Support\RegenerationPlanner;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    if (Schema::hasTable('projects')) {
        Schema::drop('projects');
    }

    DB::table('migrations')
        ->where('migration', 'like', '%_projects_table')
        ->delete();

    File::delete(GenerationPathResolver::model('Project'));
    File::delete(GenerationPathResolver::factory('ProjectFactory'));
    File::delete(GenerationPathResolver::seeder('ProjectSeeder'));
    File::delete(GenerationPathResolver::resource('ProjectResource'));

    $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
    if (File::isDirectory($resourceDir)) {
        File::deleteDirectory($resourceDir);
    }

    foreach (File::glob(database_path('migrations/*_projects_table.php')) as $migration) {
        File::delete($migration);
    }
});

it('builds a regeneration plan with preserved pages and deferred risky removals', function () {
    config()->set('architect.resources_namespace', 'App\\Filament\\Resources');

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('legacy')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
    File::ensureDirectoryExists("{$resourceDir}/Pages");
    File::put("{$resourceDir}/Pages/ListProjects.php", '<?php class ListProjects {}');

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_resource' => true,
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'soft_deletes' => false,
    ]);

    $plan = app(RegenerationPlanner::class)->plan($blueprint);

    expect($plan->hasRiskySchemaChanges())->toBeTrue()
        ->and($plan->toPreviewString())->toContain('Remove column legacy')
        ->and($plan->toPreviewString())->toContain('Remove soft deletes column');

    $artifacts = collect($plan->artifacts)->keyBy('label');

    expect($artifacts['Resource Page: List']->action)->toBe('preserve')
        ->and($artifacts['Resource Page: Create']->action)->toBe('create')
        ->and($artifacts['Filament Resource']->action)->toBe('create');
});

it('reports safe additions alongside deferred risky removals', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('legacy')->nullable();
        $table->timestamps();
    });

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'text'],
        ],
    ]);

    $plan = app(RegenerationPlanner::class)->plan($blueprint);

    expect($plan->hasRiskySchemaChanges())->toBeTrue()
        ->and($plan->toPreviewString())->toContain('Add column summary')
        ->and($plan->toPreviewString())->toContain('Remove column legacy')
        ->and(collect($plan->schemaOperations)->contains(fn ($operation) => $operation->deferred && $operation->description === 'Remove column legacy'))->toBeTrue();
});

it('detects likely renames and marks them deferred until explicitly allowed', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('legacy_name');
        $table->timestamps();
    });

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'display_name', 'type' => 'string'],
        ],
    ]);

    $plan = app(RegenerationPlanner::class)->plan($blueprint);

    expect($plan->toPreviewString())->toContain('Rename column legacy_name → display_name [risky, deferred]');

    $confirmedPlan = app(RegenerationPlanner::class)->plan(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'allow_likely_renames' => true,
        'columns' => [
            ['name' => 'display_name', 'type' => 'string'],
        ],
    ]));

    expect($confirmedPlan->toPreviewString())->toContain('Rename column legacy_name → display_name [risky]')
        ->and($confirmedPlan->toPreviewString())->not->toContain('[risky, deferred]');
});

it('groups artifacts and schema operations into safe risky and deferred buckets', function () {
    config()->set('architect.resources_namespace', 'App\\Filament\\Resources');

    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('legacy_name');
        $table->timestamps();
    });

    $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
    File::ensureDirectoryExists("{$resourceDir}/Pages");
    File::put(GenerationPathResolver::resource('ProjectResource'), '<?php class ProjectResource {}');
    File::put("{$resourceDir}/Pages/ListProjects.php", '<?php class ListProjects {}');

    $plan = app(RegenerationPlanner::class)->plan(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_resource' => true,
        'generation_mode' => 'replace',
        'soft_deletes' => true,
        'columns' => [
            ['name' => 'display_name', 'type' => 'string'],
        ],
    ]));

    $artifactGroups = $plan->groupedArtifacts();
    $schemaGroups = $plan->groupedSchemaOperations();

    expect(collect($artifactGroups['risky'])->pluck('label')->all())->toContain('Filament Resource')
        ->and(collect($artifactGroups['safe'])->pluck('label')->all())->toContain('Resource Page: List')
        ->and(collect($schemaGroups['safe'])->pluck('description')->all())->toContain('Add soft deletes column')
        ->and(collect($schemaGroups['deferred'])->pluck('description')->all())->toContain('Rename column legacy_name → display_name');
});

it('includes rationale text for grouped artifacts and schema operations', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('legacy');
        $table->timestamps();
    });

    $plan = app(RegenerationPlanner::class)->plan(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]));

    $schemaReasons = collect($plan->schemaOperations)->pluck('reason')->filter()->all();
    $deferredSchemaReasons = collect($plan->groupedSchemaOperations()['deferred'])->pluck('reason')->filter()->all();
    $artifactReasons = collect($plan->artifacts)->pluck('reason')->filter()->all();

    expect($schemaReasons)->not->toBeEmpty()
        ->and($deferredSchemaReasons)->not->toBeEmpty()
        ->and($artifactReasons)->not->toBeEmpty();
});

it('marks required new columns without defaults as deferred risky additions on populated tables', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    DB::table('projects')->insert([
        'title' => 'Existing project',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = app(RegenerationPlanner::class)->plan(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'subject', 'type' => 'string'],
        ],
    ]));

    expect($plan->hasBlockingSchemaChanges())->toBeTrue()
        ->and($plan->toPreviewString())->toContain('Add column subject [risky, deferred]')
        ->and($plan->toPreviewString())->toContain('SQLite cannot add a NOT NULL column in this situation');
});

it('marks matching schemas as a no-op plan', function () {
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $plan = app(RegenerationPlanner::class)->plan(BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]));

    expect($plan->hasSchemaChanges())->toBeFalse()
        ->and($plan->schemaOperations)->toHaveCount(1)
        ->and($plan->schemaOperations[0]->action)->toBe('noop');
});

