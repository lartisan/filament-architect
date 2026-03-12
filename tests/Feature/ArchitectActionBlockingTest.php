<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Exceptions\InvalidBlueprintException;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\Models\BlueprintRevision;
use Lartisan\Architect\Support\BlueprintGenerationService;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

afterEach(function () {
    File::delete(GenerationPathResolver::model('Comment'));
    File::delete(GenerationPathResolver::factory('CommentFactory'));
    File::delete(GenerationPathResolver::seeder('CommentSeeder'));
    File::delete(GenerationPathResolver::resource('CommentResource'));

    $resourceDir = GenerationPathResolver::resourceDirectory('CommentResource');
    if (File::isDirectory($resourceDir)) {
        File::deleteDirectory($resourceDir);
    }

    DB::table('architect_blueprint_revisions')->delete();
    DB::table('architect_blueprints')->delete();

    if (Schema::hasTable('comments')) {
        Schema::drop('comments');
    }
});

it('halts generation when adding a required column without a default to a populated table', function () {
    Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->timestamps();
    });

    DB::table('comments')->insert([
        'user_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'comments',
        'model_name' => 'Comment',
        'primary_key_type' => 'id',
        'soft_deletes' => false,
        'columns' => [
            [
                'name' => 'user_id',
                'type' => 'foreignId',
                'default' => null,
                'is_nullable' => false,
                'is_unique' => false,
                'is_index' => false,
            ],
            [
                'name' => 'subject',
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
        'allow_destructive_changes' => false,
        'allow_likely_renames' => false,
        'run_migration' => false,
    ]);

    expect(fn () => app(BlueprintGenerationService::class)->generate($blueprint))
        ->toThrow(InvalidBlueprintException::class);

    expect(ArchitectBlueprint::query()->where('table_name', 'comments')->doesntExist())->toBeTrue()
        ->and(BlueprintRevision::query()->doesntExist())->toBeTrue()
        ->and(File::exists(GenerationPathResolver::model('Comment')))->toBeFalse()
        ->and(File::exists(GenerationPathResolver::resource('CommentResource')))->toBeFalse();
});
