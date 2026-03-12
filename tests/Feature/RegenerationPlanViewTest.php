<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Support\RegenerationPlanner;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    if (Schema::hasTable('projects')) {
        Schema::drop('projects');
    }
});

it('renders a dedicated blocking section for unsafe required column additions', function () {
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

    $html = view('architect::components.regeneration-plan', [
        'plan' => $plan,
    ])->render();

    expect($html)
        ->toContain('Regeneration summary')
        ->toContain('Blocking changes')
        ->toContain('Artifacts')
        ->toContain('Will write / update')
        ->toContain('Schema changes')
        ->toContain('Awaiting confirmation')
        ->toContain('Add column subject')
        ->toContain('Migration')
        ->toContain('Model')
        ->not->toContain('language-markdown');
});

it('renders an empty state when there is no plan yet', function () {
    $html = view('architect::components.regeneration-plan', [
        'plan' => null,
    ])->render();

    expect($html)
        ->toContain('Enter table details to preview the regeneration summary...')
        ->not->toContain('Regeneration summary');
});
