<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\FactoryGenerator;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

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
