<?php

use Illuminate\Support\Facades\File;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

beforeEach(function () {
    config()->set('architect.models_namespace', modelRegenerationTestModelsNamespace());
});

afterEach(function () {
    File::delete(GenerationPathResolver::model('Project'));
    File::delete(GenerationPathResolver::model('Post'));
    File::delete(app_path('Domain/Catalog/Models/Product.php'));

    if (File::isDirectory(modelRegenerationTestModelsRoot())) {
        File::deleteDirectory(modelRegenerationTestModelsRoot());
    }

    if (File::isDirectory(app_path('Domain'))) {
        File::deleteDirectory(app_path('Domain'));
    }
});

it('merges generated model parts without removing custom code', function () {
    $path = GenerationPathResolver::model('Project');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

namespace App\Testing\ModelRegeneration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy',
    ];

    public function customSummary(): string
    {
        return 'kept';
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'soft_deletes' => true,
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'user_id', 'type' => 'foreignId'],
        ],
    ]);

    $generatedPath = (new ModelGenerator)->generate($blueprint);
    $content = File::get($generatedPath);

    expect($generatedPath)->toBe($path)
        ->and($content)->toContain("use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\n\nclass Project extends Model")
        ->and($content)->toContain("use SoftDeletes;\n\n    protected ")
        ->and($content)->toContain('];

    public function customSummary(): string')
        ->and($content)->toContain('    }

    public function user(): BelongsTo')
        ->and($content)->toContain("'legacy'")
        ->and($content)->toContain("'title'")
        ->and($content)->toContain("'user_id'")
        ->and($content)->toContain('use Illuminate\\Database\\Eloquent\\SoftDeletes;')
        ->and($content)->toContain('use SoftDeletes;')
        ->and($content)->toContain('return $this->belongsTo(User::class);');
});

it('keeps an existing relationship method intact during merge regeneration', function () {
    $path = GenerationPathResolver::model('Post');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

namespace App\Testing\ModelRegeneration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'user_id', 'type' => 'foreignId'],
        ],
    ]);

    (new ModelGenerator)->generate($blueprint);

    $content = File::get($path);

    expect(substr_count($content, 'public function user(): BelongsTo'))->toBe(1)
        ->and($content)->toContain("return \$this->belongsTo(User::class, 'owner_id');");
});

it('uses the selected related table model for newly merged relationship methods', function () {
    $path = GenerationPathResolver::model('Post');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

namespace App\Testing\ModelRegeneration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
    ];
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'author_id', 'type' => 'foreignId', 'relationship_table' => 'users'],
        ],
    ]);

    (new ModelGenerator)->generate($blueprint);

    $content = File::get($path);

    expect($content)
        ->toContain('public function author(): BelongsTo')
        ->toContain('return $this->belongsTo(User::class);');
});

it('writes models to the configured models namespace path', function () {
    config()->set('architect.models_namespace', 'App\\Domain\\Catalog\\Models');

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'products',
        'model_name' => 'Product',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $path = (new ModelGenerator)->generate($blueprint);

    expect($path)->toBe(app_path('Domain/Catalog/Models/Product.php'))
        ->and(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toContain('namespace App\\Domain\\Catalog\\Models;');
});

function modelRegenerationTestModelsNamespace(): string
{
    return 'App\\Testing\\ModelRegeneration\\Models';
}

function modelRegenerationTestModelsRoot(): string
{
    return dirname(GenerationPathResolver::model('Project'));
}
