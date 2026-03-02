<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;
use function Pest\Laravel\assertDatabaseHas;

uses(TestCase::class);

afterEach(function () {
    // Cleanup after each test
    cleanupAllGeneratedFiles();
});

function cleanupAllGeneratedFiles(): void
{
    // Clean database tables
    $tables = ['posts', 'articles'];
    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }

        // Clean migration records
        \DB::table('migrations')
            ->where('migration', 'like', "%_create_{$table}_table")
            ->delete();
    }

    // Clean Post model and resources
    @unlink(app_path('Models/Post.php'));
    @unlink(app_path('Models/Article.php'));

    if (File::isDirectory(app_path('Filament/Resources/PostResource'))) {
        File::deleteDirectory(app_path('Filament/Resources/PostResource'));
    }
    @unlink(app_path('Filament/Resources/PostResource.php'));

    if (File::isDirectory(app_path('Filament/Resources/ArticleResource'))) {
        File::deleteDirectory(app_path('Filament/Resources/ArticleResource'));
    }
    @unlink(app_path('Filament/Resources/ArticleResource.php'));

    // Clean migrations
    $migrationsPath = database_path('migrations');
    if (File::isDirectory($migrationsPath)) {
        $migrations = File::glob($migrationsPath . '/*.php');
        foreach ($migrations as $migration) {
            if (str_contains($migration, '_create_posts_table') ||
                str_contains($migration, '_create_articles_table')) {
                @unlink($migration);
            }
        }
    }
}

it('generates all necessary files for a complete resource', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'content', 'type' => 'text'],
            ['name' => 'published_at', 'type' => 'timestamp', 'is_nullable' => true],
        ],
        'gen_resource' => true,
        'gen_factory' => false,
        'gen_seeder' => false,
    ]);

    // Generate migration
    $migrationPath = (new MigrationGenerator)->generate($blueprint);
    expect(File::exists($migrationPath))->toBeTrue();

    $migrationContent = File::get($migrationPath);
    expect($migrationContent)
        ->toContain('Schema::create(\'posts\'')
        ->toContain('$table->string(\'title\')')
        ->toContain('$table->text(\'content\')')
        ->toContain('$table->timestamp(\'published_at\')->nullable()');

    // Generate model
    $modelPath = (new ModelGenerator)->generate($blueprint);
    expect(File::exists($modelPath))->toBeTrue();

    $modelContent = File::get($modelPath);
    expect($modelContent)
        ->toContain('class Post extends Model')
        ->toContain('protected $fillable = [')
        ->toContain("'title'")
        ->toContain("'content'")
        ->toContain("'published_at'");

    // Generate Filament resource
    $resourcePath = (new FilamentResourceGenerator)->generate($blueprint);
    expect(File::exists($resourcePath))->toBeTrue();

    $resourceContent = File::get($resourcePath);
    expect($resourceContent)
        ->toContain('class PostResource extends Resource')
        ->toContain('Forms\Components\TextInput::make(\'title\')')
        ->toContain('Forms\Components\Textarea::make(\'content\')')
        ->toContain('Tables\Columns\TextColumn::make(\'title\')');

    // Check resource pages
    $resourceDir = app_path('Filament/Resources/PostResource');
    expect(File::exists("$resourceDir/Pages/ListPosts.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/CreatePost.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/EditPost.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/ViewPost.php"))->toBeTrue();

    // Cleanup immediately after this test
    File::delete($migrationPath);
    File::delete($modelPath);
    File::delete($resourcePath);
    File::deleteDirectory($resourceDir);
});

it('can run migrations and create tables with proper schema', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
            ['name' => 'content', 'type' => 'text'],
            ['name' => 'views', 'type' => 'integer', 'default' => 0],
        ],
        'soft_deletes' => true,
    ]);

    $migrationPath = (new MigrationGenerator)->generate($blueprint);
    (new ModelGenerator)->generate($blueprint);

    // Run migrations
    Artisan::call('migrate', ['--path' => 'database/migrations']);

    // Verify table exists with correct schema
    expect(Schema::hasTable('posts'))->toBeTrue()
        ->and(Schema::hasColumn('posts', 'title'))->toBeTrue()
        ->and(Schema::hasColumn('posts', 'slug'))->toBeTrue()
        ->and(Schema::hasColumn('posts', 'content'))->toBeTrue()
        ->and(Schema::hasColumn('posts', 'views'))->toBeTrue()
        ->and(Schema::hasColumn('posts', 'deleted_at'))->toBeTrue();

    // Cleanup for next test
    Schema::drop('posts');
    \DB::table('migrations')->where('migration', 'like', '%_create_posts_table')->delete();
    File::delete($migrationPath);
    File::delete(app_path('Models/Post.php'));
});

it('can perform full CRUD operations on generated models', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'blog_posts',
        'model_name' => 'BlogPost',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
            ['name' => 'view_count', 'type' => 'integer', 'default' => 0],
        ],
    ]);

    $migrationPath = (new MigrationGenerator)->generate($blueprint);
    $modelPath = (new ModelGenerator)->generate($blueprint);

    // Run migration
    Artisan::call('migrate', ['--path' => 'database/migrations']);

    // Verify table was created
    expect(Schema::hasTable('blog_posts'))->toBeTrue();

    // Load generated model
    require_once $modelPath;

    // Test model functionality
    $modelClass = 'App\\Models\\BlogPost';

    // CREATE
    $model = $modelClass::create([
        'title' => 'Test Article',
        'body' => 'This is test content',
        'view_count' => 5,
    ]);

    expect($model->exists)->toBeTrue()
        ->and($model->title)->toBe('Test Article')
        ->and($model->body)->toBe('This is test content')
        ->and($model->view_count)->toBe(5);

    assertDatabaseHas('blog_posts', [
        'title' => 'Test Article',
        'body' => 'This is test content',
    ]);

    // UPDATE
    $model->update(['title' => 'Updated Title']);
    expect($model->fresh()->title)->toBe('Updated Title');
    assertDatabaseHas('blog_posts', ['title' => 'Updated Title']);

    // DELETE
    $modelId = $model->id;
    $model->delete();
    expect($modelClass::find($modelId))->toBeNull();

    // Cleanup
    Schema::drop('blog_posts');
    \DB::table('migrations')->where('migration', 'like', '%_create_blog_posts_table')->delete();
    File::delete($migrationPath);
    File::delete($modelPath);
});

