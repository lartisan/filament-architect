<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\SeederGenerator;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

beforeEach(function () {
    config()->set('architect.models_namespace', seederGeneratorTestModelsNamespace());
    config()->set('architect.seeders_namespace', seederGeneratorTestSeedersNamespace());
});

afterEach(function () {
    File::delete(GenerationPathResolver::seeder('ProjectSeeder'));

    if (File::isDirectory(seederGeneratorTestSeedersRoot())) {
        File::deleteDirectory(seederGeneratorTestSeedersRoot());
    }
});

it('generates a seeder file', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_seeder' => true,
        'columns' => [],
    ]);

    $generator = new SeederGenerator;
    $path = $generator->generate($blueprint);

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('class ProjectSeeder extends Seeder')
        ->toContain('// <architect:seed>');

    File::delete($path);
});

it('hides architect seed markers from preview only', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_seeder' => true,
        'columns' => [],
    ]);

    $preview = (new SeederGenerator)->preview($blueprint);

    expect($preview)
        ->toContain('Project::factory()->count(10)->create();')
        ->not->toContain('// <architect:seed>')
        ->not->toContain('// </architect:seed>');
});

it('merges the managed seeder region without removing custom logic', function () {
    $path = GenerationPathResolver::seeder('ProjectSeeder');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

namespace Database\Testing\SeederGenerator\Seeders;

use App\Testing\SeederGenerator\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        Project::query()->delete();

        // <architect:seed>
        Project::factory()->count(3)->create();
        // </architect:seed>

        logger('done');
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_seeder' => true,
        'generation_mode' => 'merge',
        'columns' => [],
    ]);

    $generator = new SeederGenerator;
    $generatedPath = $generator->generate($blueprint);
    $content = File::get($generatedPath);

    expect($generatedPath)->toBe($path)
        ->and($content)->toContain('Project::query()->delete();')
        ->and($content)->toContain('Project::factory()->count(10)->create();')
        ->and($content)->toContain("logger('done');");
});

function seederGeneratorTestModelsNamespace(): string
{
    return 'App\\Testing\\SeederGenerator\\Models';
}

function seederGeneratorTestSeedersNamespace(): string
{
    return 'Database\\Testing\\SeederGenerator\\Seeders';
}

function seederGeneratorTestSeedersRoot(): string
{
    return dirname(GenerationPathResolver::seeder('ProjectSeeder'));
}

