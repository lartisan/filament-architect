<?php

namespace Lartisan\Architect\Tests\Generators;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Generators\FilamentResourceGenerator;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

beforeEach(function () {
    config()->set('architect.resources_namespace', testResourcesNamespace());
    config()->set('architect.models_namespace', testModelsNamespace());
});

afterEach(function () {
    if (File::isDirectory(testResourcesRoot())) {
        File::deleteDirectory(testResourcesRoot());
    }

    foreach (['User', 'Post', 'Author', 'Category'] as $model) {
        File::delete(GenerationPathResolver::model($model));
    }

    if (File::isDirectory(testModelsRoot())) {
        File::deleteDirectory(testModelsRoot());
    }

    foreach (['users', 'posts', 'authors', 'categories'] as $table) {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }
    }
});

it('generates a filament resource and pages', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'gen_resource' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);

    expect(File::exists($path))->toBeTrue();

    $content = File::get($path);

    expect($content)
        ->toContain('class ProjectResource extends Resource')
        ->toContain('Forms\Components\TextInput::make(\'title\')')
        ->toContain('Tables\Columns\TextColumn::make(\'title\')');

    // Check pages
    $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
    expect(File::exists("$resourceDir/Pages/ListProjects.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/CreateProject.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/EditProject.php"))->toBeTrue()
        ->and(File::exists("$resourceDir/Pages/ViewProject.php"))->toBeTrue();

    // Cleanup
    File::deleteDirectory(testResourcesRoot());
});

it('generates a filament resource with soft deletes', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
        'gen_resource' => true,
        'soft_deletes' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);

    $content = File::get($path);

    expect($content)
        ->toContain('use Illuminate\Database\Eloquent\SoftDeletingScope;')
        ->toContain('Tables\Filters\TrashedFilter::make()')
        ->toContain('\Filament\Actions\ForceDeleteBulkAction::make()')
        ->toContain('\Filament\Actions\RestoreBulkAction::make()')
        ->toContain('public static function getEloquentQuery(): Builder');

    File::deleteDirectory(testResourcesRoot());
});

it('generates proper select components for all foreign key types', function () {
    writeTestModel('User');
    writeTestModel('Post');
    writeTestModel('Author');
    writeTestModel('Category');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
    });

    Schema::create('authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->string('label');
    });

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'comments',
        'model_name' => 'Comment',
        'columns' => [
            ['name' => 'user_id', 'type' => 'foreignId', 'is_nullable' => false],
            ['name' => 'post_id', 'type' => 'foreignId', 'is_nullable' => false],
            ['name' => 'author_uuid', 'type' => 'foreignUuid', 'is_index' => true],
            ['name' => 'category_ulid', 'type' => 'foreignUlid', 'is_unique' => true],
        ],
        'gen_resource' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)->toContain("Forms\Components\Select::make('user_id')")
        ->toContain("->relationship('user', 'name')")
        ->toContain("Forms\Components\Select::make('post_id')")
        ->toContain("->relationship('post', 'title')")
        ->toContain('->required()')
        ->and($content)->toContain("Forms\Components\Select::make('author_uuid')")
        ->toContain("->relationship('author', 'name')")
        ->toContain('->searchable()')
        ->and($content)->toContain("Forms\Components\Select::make('category_ulid')")
        ->toContain("->relationship('category', 'label')")
        ->toContain('->unique(ignoreRecord: true)');

    File::deleteDirectory(testResourcesRoot());
});

it('falls back to the related key when no safe relationship title attribute can be inferred', function () {
    $blueprint = BlueprintData::fromArray([
        'table_name' => 'comments',
        'model_name' => 'Comment',
        'columns' => [
            ['name' => 'post_id', 'type' => 'foreignId'],
        ],
        'gen_resource' => true,
    ]);

    $content = File::get((new FilamentResourceGenerator)->generate($blueprint));

    expect($content)->toContain("->relationship('post', 'id')")
        ->and($content)->toContain("Tables\\Columns\\TextColumn::make('post.id')");
});

it('generates relationship columns in table with the inferred display attribute', function () {
    writeTestModel('Author');

    Schema::create('authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            ['name' => 'author_uuid', 'type' => 'foreignUuid'],
        ],
        'gen_resource' => true,
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    expect($content)
        ->toContain("Tables\Columns\TextColumn::make('author.name')")
        ->toContain("->label('Author')")
        ->toContain('->sortable()')
        ->toContain('->searchable()');

    File::deleteDirectory(app_path('Filament'));
});

it('merges missing generated resource pieces without removing customizations', function () {
    writeTestModel('User');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    $resourcePath = GenerationPathResolver::resource('ProjectResource');
    File::ensureDirectoryExists(dirname($resourcePath));
    File::put($resourcePath, <<<'PHP'
<?php

namespace App\Testing\FilamentResourceGenerator\Filament\Resources;

use App\Testing\FilamentResourceGenerator\Filament\Resources\ProjectResource\Pages;
use App\Testing\FilamentResourceGenerator\Models\Project;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Forms\Components\TextInput::make('title')->maxLength(120), Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([Tables\Columns\TextColumn::make('title')->sortable()])->filters([Tables\Filters\Filter::make('customFilter')])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()])->toolbarActions([\Filament\Actions\BulkActionGroup::make([\Filament\Actions\DeleteBulkAction::make()])]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('title'), TextEntry::make('slug'), TextEntry::make('content')]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProjects::route('/'), 'create' => Pages\CreateProject::route('/create'), 'edit' => Pages\EditProject::route('/{record}/edit'), 'view' => Pages\ViewProject::route('/{record}')];
    }

    public static function customWidgets(): array
    {
        return ['kept'];
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_resource' => true,
        'generation_mode' => 'merge',
        'soft_deletes' => true,
        'columns' => [
            ['name' => 'user_id', 'type' => 'foreignId'],
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
            ['name' => 'content', 'type' => 'text'],
            ['name' => 'excerpt', 'type' => 'text'],
        ],
    ]);

    $generator = new FilamentResourceGenerator;
    $path = $generator->generate($blueprint);
    $content = File::get($path);

    $userSelectPosition = strpos($content, "Forms\\Components\\Select::make('user_id')");
    $titleInputPosition = strpos($content, "Forms\\Components\\TextInput::make('title')");
    $userInfolistPosition = strpos($content, "TextEntry::make('user_id')");
    $titleInfolistPosition = strpos($content, "TextEntry::make('title')");

    expect($path)->toBe($resourcePath)
        ->and($content)->toContain("protected static ?string \$model = Project::class;\n\n    protected static \\BackedEnum|string|null \$navigationIcon")
        ->and($content)->toContain("protected static \\BackedEnum|string|null \$navigationIcon = 'heroicon-o-rectangle-stack';\n\n    public static function form")
        ->and($content)->toContain("return \$schema\n            ->components([")
        ->and($content)->toContain("\n                Forms\\Components\\Select::make('user_id')\n                    ->relationship('user', 'name')\n                    ->required()")
        ->and($userSelectPosition)->toBeLessThan($titleInputPosition)
        ->and($content)->toContain("\n                Forms\\Components\\TextInput::make('slug')\n                    ->required()\n                    ->unique(ignoreRecord: true)")
        ->and($content)->toContain("return \$table\n            ->columns([")
        ->and($content)->toContain("return \$schema\n            ->components([")
        ->and($userInfolistPosition)->toBeLessThan($titleInfolistPosition)
        ->and(substr_count($content, "TextEntry::make('user_id')"))->toBe(1)
        ->and(substr_count($content, "TextEntry::make('title')"))->toBe(1)
        ->and(substr_count($content, "TextEntry::make('slug')"))->toBe(1)
        ->and(substr_count($content, "TextEntry::make('content')"))->toBe(1)
        ->and(substr_count($content, "TextEntry::make('excerpt')"))->toBe(1)
        ->and($content)->toContain("public static function getPages(): array\n    {\n        return [\n            'index' => Pages\\ListProjects::route('/'),")
        ->and($content)->toContain("\n            'view' => Pages\\ViewProject::route('/{record}')")
        ->and($content)->toContain("\n        ];")
        ->and($content)->toContain("Tables\\Filters\\Filter::make('customFilter')")
        ->and($content)->toContain('Tables\\Filters\\TrashedFilter::make()')
        ->and($content)->toContain('\\Filament\\Actions\\ForceDeleteBulkAction::make()')
        ->and($content)->toContain('\\Filament\\Actions\\RestoreBulkAction::make()')
        ->and($content)->toContain('public static function getEloquentQuery(): Builder')
        ->and($content)->toContain('public static function customWidgets(): array');
});

it('preserves existing resource page classes while creating missing ones', function () {
    $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
    File::ensureDirectoryExists("{$resourceDir}/Pages");
    File::put("{$resourceDir}/Pages/ListProjects.php", <<<'PHP'
<?php

namespace App\Testing\FilamentResourceGenerator\Filament\Resources\ProjectResource\Pages;

class ListProjects
{
    public function customPageHook(): string
    {
        return 'keep-me';
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_resource' => true,
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    (new FilamentResourceGenerator)->generate($blueprint);

    expect(File::get("{$resourceDir}/Pages/ListProjects.php"))->toContain("return 'keep-me';")
        ->and(File::exists("{$resourceDir}/Pages/CreateProject.php"))->toBeTrue()
        ->and(File::exists("{$resourceDir}/Pages/EditProject.php"))->toBeTrue()
        ->and(File::exists("{$resourceDir}/Pages/ViewProject.php"))->toBeTrue();
});

it('removes stale managed resource fields when columns are deleted while keeping custom items', function () {
    $resourcePath = GenerationPathResolver::resource('ProjectResource');
    File::ensureDirectoryExists(dirname($resourcePath));
    File::put($resourcePath, <<<'PHP'
<?php

namespace App\Testing\FilamentResourceGenerator\Filament\Resources;

use App\Testing\FilamentResourceGenerator\Filament\Resources\ProjectResource\Pages;
use App\Testing\FilamentResourceGenerator\Models\Project;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('title')->required(),
            Forms\Components\Textarea::make('content')->required(),
            Forms\Components\Textarea::make('excerpt'),
            \App\Filament\Forms\Components\SeoPreview::make('seo_preview'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('content'),
            Tables\Columns\TextColumn::make('excerpt'),
            \App\Filament\Tables\Columns\StatusBadgeColumn::make('status_label'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            TextEntry::make('content'),
            TextEntry::make('excerpt'),
            \App\Filament\Infolists\Components\AuditEntry::make('audit_log'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
        ];
    }
}
PHP);

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'projects',
        'model_name' => 'Project',
        'gen_resource' => true,
        'generation_mode' => 'merge',
        'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);

    $content = File::get((new FilamentResourceGenerator)->generate($blueprint));

    expect($content)->toContain("Forms\\Components\\TextInput::make('title')")
        ->and($content)->not->toContain("Forms\\Components\\Textarea::make('content')")
        ->and($content)->not->toContain("Forms\\Components\\Textarea::make('excerpt')")
        ->and($content)->toContain("\\App\\Filament\\Forms\\Components\\SeoPreview::make('seo_preview')")
        ->and($content)->not->toContain("Tables\\Columns\\TextColumn::make('content')")
        ->and($content)->not->toContain("Tables\\Columns\\TextColumn::make('excerpt')")
        ->and($content)->toContain("\\App\\Filament\\Tables\\Columns\\StatusBadgeColumn::make('status_label')")
        ->and($content)->not->toContain("TextEntry::make('content')")
        ->and($content)->not->toContain("TextEntry::make('excerpt')")
        ->and($content)->toContain("\\App\\Filament\\Infolists\\Components\\AuditEntry::make('audit_log')");
});

it('prefers explicitly selected relationship metadata over inferred title attributes', function () {
    writeTestModel('Post');

    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('headline');
    });

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'comments',
        'model_name' => 'Comment',
        'columns' => [
            [
                'name' => 'post_id',
                'type' => 'foreignId',
                'relationship_table' => 'posts',
                'relationship_title_column' => 'headline',
            ],
        ],
        'gen_resource' => true,
    ]);

    $content = File::get((new FilamentResourceGenerator)->generate($blueprint));

    expect($content)->toContain("->relationship('post', 'headline')")
        ->and($content)->toContain("Tables\\Columns\\TextColumn::make('post.headline')");
});

it('keeps the foreign key field name in the form while using the selected relationship title column', function () {
    writeTestModel('User');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    $blueprint = BlueprintData::fromArray([
        'table_name' => 'posts',
        'model_name' => 'Post',
        'columns' => [
            [
                'name' => 'author_id',
                'type' => 'foreignId',
                'relationship_table' => 'users',
                'relationship_title_column' => 'name',
            ],
        ],
        'gen_resource' => true,
    ]);

    $content = File::get((new FilamentResourceGenerator)->generate($blueprint));

    expect($content)
        ->toContain("Forms\\Components\\Select::make('author_id')")
        ->toContain("->relationship('author', 'name')")
        ->toContain("Tables\\Columns\\TextColumn::make('author.name')")
        ->not->toContain("Forms\\Components\\Select::make('author.name')");
});

function testResourcesNamespace(): string
{
    return 'App\\Testing\\FilamentResourceGenerator\\Filament\\Resources';
}

function testModelsNamespace(): string
{
    return 'App\\Testing\\FilamentResourceGenerator\\Models';
}

function testResourcesRoot(): string
{
    return dirname(dirname(GenerationPathResolver::resource('ProjectResource')));
}

function testModelsRoot(): string
{
    return dirname(GenerationPathResolver::model('Project'));
}

function writeTestModel(string $modelName): void
{
    $path = GenerationPathResolver::model($modelName);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<PHP
<?php

namespace {testModelsNamespace()};

use Illuminate\\Database\\Eloquent\\Model;

class {$modelName} extends Model {}
PHP);
}
