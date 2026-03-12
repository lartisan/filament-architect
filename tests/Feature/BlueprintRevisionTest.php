<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    DB::table('architect_blueprint_revisions')->delete();
    DB::table('architect_blueprints')->delete();

    File::delete(GenerationPathResolver::model('Post'));
    File::delete(GenerationPathResolver::factory('PostFactory'));
    File::delete(GenerationPathResolver::seeder('PostSeeder'));
    File::delete(GenerationPathResolver::resource('PostResource'));

    $resourceDir = GenerationPathResolver::resourceDirectory('PostResource');
    if (File::isDirectory($resourceDir)) {
        File::deleteDirectory($resourceDir);
    }

    foreach (File::glob(database_path('migrations/*_posts_table.php')) as $migration) {
        File::delete($migration);
    }

    DB::table('migrations')
        ->where('migration', 'like', '%_posts_table')
        ->delete();
});

it('records a blueprint revision snapshot', function () {
    $blueprint = Blueprint::create([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'primary_key_type' => 'id',
        'columns' => [],
        'soft_deletes' => false,
        'meta' => [
            'gen_factory' => true,
            'gen_seeder' => true,
            'gen_resource' => true,
            'generation_mode' => 'merge',
        ],
    ]);

    $revision = $blueprint->recordRevision(BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'primary_key_type' => 'id',
        'columns' => [
            [
                'name' => 'title',
                'type' => 'string',
                'default' => null,
                'is_nullable' => false,
                'is_unique' => false,
                'is_index' => false,
            ],
        ],
        'gen_factory' => true,
        'gen_seeder' => true,
        'gen_resource' => true,
        'generation_mode' => 'merge',
        'run_migration' => false,
    ]));

    expect($revision->revision)->toBe(1)
        ->and($revision->snapshot['table_name'] ?? null)->toBe('posts')
        ->and($revision->snapshot['columns'][0]['name'] ?? null)->toBe('title');
});
