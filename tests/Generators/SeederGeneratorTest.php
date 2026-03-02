<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\SeederGenerator;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

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
        ->toContain('class ProjectSeeder extends Seeder');

    // Cleanup
    File::delete($path);
});
