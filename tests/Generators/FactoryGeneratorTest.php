<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\FactoryGenerator;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    File::delete(GenerationPathResolver::factory('ProjectFactory'));
});

it('generates a factory file', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_factory' => true,
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $generator = new FactoryGenerator;
    $path = $generator->generate($blueprint);

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('class ProjectFactory extends Factory')
        ->toContain("'title' => \$this->faker->"); // Expecting faker method

    // Cleanup
    File::delete($path);
});

it('skips factory generation if not requested', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'gen_factory' => false,
        'columns' => [],
    ]);

    $generator = new FactoryGenerator;
    $path = $generator->generate($blueprint);

    expect($path)->toBeEmpty();
});

it('merges missing factory definition keys without overwriting custom values', function () {
    $path = GenerationPathResolver::factory('ProjectFactory');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'title' => 'custom-title',
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived' => true]);
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_factory' => true,
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'description', 'type' => 'text'],
        ],
    ]);

    $generator = new FactoryGenerator;
    $generatedPath = $generator->generate($blueprint);
    $content = File::get($generatedPath);

    expect($generatedPath)->toBe($path)
        ->and($content)->toContain("public function definition(): array\n    {\n        return [")
        ->and($content)->toContain("\n            'title' => 'custom-title',")
        ->and($content)->toContain("\n            'description' => \$this->faker->paragraphs(3, true)\n")
        ->and($content)->toContain("\n        ];\n    }")
        ->and($content)->toContain("protected \$model = Project::class;\n\n    public function definition(): array")
        ->and($content)->toContain("    }\n\n    public function archived(): static")
        ->and($content)->toContain("'title' => 'custom-title'")
        ->and($content)->toContain("'description' => \$this->faker->paragraphs(3, true)")
        ->and($content)->toContain('public function archived(): static');
});
