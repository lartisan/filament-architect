<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

it('generates a model file with correct content', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'soft_deletes' => true,
    ]);

    $generator = new ModelGenerator;
    $path = $generator->generate($blueprint);

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('class Project extends Model')
        ->toContain('use SoftDeletes;')
        ->toContain('use HasFactory;')
        ->toContain('protected $fillable = [')
        ->toContain("'title'");

    File::delete($path);
});

it('generates a relationship for foreignId column', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'user_id', 'type' => 'foreignId'],
        ],
    ]);

    $generator = new ModelGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)
        ->toContain('use Illuminate\Database\Eloquent\Relations\BelongsTo;')
        ->toContain('public function user(): BelongsTo')
        ->toContain('return $this->belongsTo(User::class);');

    File::delete($path);
});

it('generates a relationship for foreignUuid column', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'author_uuid', 'type' => 'foreignUuid'],
        ],
    ]);

    $generator = new ModelGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)
        ->toContain('public function author(): BelongsTo')
        ->toContain('return $this->belongsTo(Author::class);');

    File::delete($path);
});

it('generates a relationship for foreignUlid column', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'category_ulid', 'type' => 'foreignUlid'],
        ],
    ]);

    $generator = new ModelGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)
        ->toContain('public function category(): BelongsTo')
        ->toContain('return $this->belongsTo(Category::class);');

    File::delete($path);
});

it('generates a relationship based on name suffix even if type is string', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'owner_id', 'type' => 'string'],
        ],
    ]);

    $generator = new ModelGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)
        ->toContain('public function owner(): BelongsTo')
        ->toContain('return $this->belongsTo(Owner::class);');

    File::delete($path);
});

it('includes foreign keys in fillable attributes', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'user_id', 'type' => 'foreignId'],
            ['name' => 'category_uuid', 'type' => 'foreignUuid'],
        ],
    ]);

    $generator = new ModelGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)->toContain("'title'")
        ->and($content)->toContain("'user_id'")
        ->and($content)->toContain("'category_uuid'");

    File::delete($path);
});
