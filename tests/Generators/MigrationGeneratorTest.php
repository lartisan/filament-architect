<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

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
