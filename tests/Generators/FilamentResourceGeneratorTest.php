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

    foreach (['User', 'Post', 'Author', 'Category', 'Comment'] as $model) {
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

// ---------------------------------------------------------------------------
// v3 — flat / monolithic structure
// ---------------------------------------------------------------------------

describe('v3 (flat / monolithic structure)', function () {
    beforeEach(fn () => useFilamentV3());

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
            ->toContain("Forms\Components\TextInput::make('title')")
            ->toContain("Tables\Columns\TextColumn::make('title')");

        // Pages are generated in the flat ResourceClass/Pages/ sub-folder
        $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
        expect(File::exists("$resourceDir/Pages/ListProjects.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/CreateProject.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/EditProject.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/ViewProject.php"))->toBeTrue();
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
});

// ---------------------------------------------------------------------------
// v4 — domain / delegated structure
// ---------------------------------------------------------------------------

describe('v4 (domain / delegated structure)', function () {
    beforeEach(fn () => useFilamentV4());

    it('generates a thin resource with delegation to separate schema and table files', function () {
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

        $resourceContent = File::get($path);

        // Resource is thin — delegates to separate classes
        expect($resourceContent)
            ->toContain('class ProjectResource extends Resource')
            ->toContain('ProjectForm::configure($schema)')
            ->toContain('ProjectInfolist::configure($schema)')
            ->toContain('ProjectsTable::configure($table)')
            // No inline form / table components
            ->not->toContain("Forms\\Components\\TextInput::make('title')")
            ->not->toContain("Tables\\Columns\\TextColumn::make('title')");

        // Separate schema / table files exist and contain the actual components
        $formPath = GenerationPathResolver::resourceSchemaFile('Project', 'Form');
        $infolistPath = GenerationPathResolver::resourceSchemaFile('Project', 'Infolist');
        $tablePath = GenerationPathResolver::resourceTableFile('Project');

        expect(File::exists($formPath))->toBeTrue()
            ->and(File::exists($infolistPath))->toBeTrue()
            ->and(File::exists($tablePath))->toBeTrue();

        expect(File::get($formPath))->toContain("Forms\Components\TextInput::make('title')");
        expect(File::get($tablePath))->toContain("Tables\Columns\TextColumn::make('title')");

        // Pages live inside the domain folder (Resources/Projects/Pages/)
        $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
        expect(File::exists("$resourceDir/Pages/ListProjects.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/CreateProject.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/EditProject.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/ViewProject.php"))->toBeTrue();
    });

    it('creates Schemas/ and Tables/ sub-directories inside the domain folder', function () {
        $blueprint = BlueprintData::fromArray([
            'table_name' => 'projects',
            'model_name' => 'Project',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        (new FilamentResourceGenerator)->generate($blueprint);

        $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');

        expect(File::isDirectory("$resourceDir/Schemas"))->toBeTrue()
            ->and(File::isDirectory("$resourceDir/Tables"))->toBeTrue()
            ->and(File::isDirectory("$resourceDir/Pages"))->toBeTrue();
    });

    it('generates correct namespaces in all generated v4 files', function () {
        $blueprint = BlueprintData::fromArray([
            'table_name' => 'projects',
            'model_name' => 'Project',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        (new FilamentResourceGenerator)->generate($blueprint);

        $baseNs = testResourcesNamespace();
        $domainNs = $baseNs.'\\Projects';
        $schemasNs = $domainNs.'\\Schemas';
        $tablesNs = $domainNs.'\\Tables';
        $pagesNs = $domainNs.'\\Pages';

        $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');

        expect(File::get(GenerationPathResolver::resource('ProjectResource')))
            ->toContain("namespace $domainNs;");

        expect(File::get(GenerationPathResolver::resourceSchemaFile('Project', 'Form')))
            ->toContain("namespace $schemasNs;");

        expect(File::get(GenerationPathResolver::resourceSchemaFile('Project', 'Infolist')))
            ->toContain("namespace $schemasNs;");

        expect(File::get(GenerationPathResolver::resourceTableFile('Project')))
            ->toContain("namespace $tablesNs;");

        expect(File::get("$resourceDir/Pages/ListProjects.php"))
            ->toContain("namespace $pagesNs;");

        expect(File::get("$resourceDir/Pages/CreateProject.php"))
            ->toContain("namespace $pagesNs;");
    });

    it('puts soft-delete filter and bulk actions in the table file and getEloquentQuery in the resource', function () {
        $blueprint = BlueprintData::fromArray([
            'table_name' => 'projects',
            'model_name' => 'Project',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
            'soft_deletes' => true,
        ]);

        $path = (new FilamentResourceGenerator)->generate($blueprint);
        $resourceContent = File::get($path);
        $tableContent = File::get(GenerationPathResolver::resourceTableFile('Project'));

        // Resource: SoftDeletingScope import + getEloquentQuery()
        expect($resourceContent)
            ->toContain('use Illuminate\Database\Eloquent\SoftDeletingScope;')
            ->toContain('public static function getEloquentQuery(): Builder')
            // Filter and bulk actions live in the table file, not the resource
            ->not->toContain('Tables\Filters\TrashedFilter::make()');

        // Table file: filter + bulk actions
        expect($tableContent)
            ->toContain('Tables\Filters\TrashedFilter::make()')
            ->toContain('\Filament\Actions\ForceDeleteBulkAction::make()')
            ->toContain('\Filament\Actions\RestoreBulkAction::make()');
    });

    it('generates select components into the form file and text columns into the table file', function () {
        writeTestModel('User');
        writeTestModel('Post');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        $blueprint = BlueprintData::fromArray([
            'table_name' => 'comments',
            'model_name' => 'Comment',
            'columns' => [
                ['name' => 'user_id', 'type' => 'foreignId', 'is_nullable' => false],
                ['name' => 'post_id', 'type' => 'foreignId', 'is_nullable' => false],
            ],
            'gen_resource' => true,
        ]);

        $path = (new FilamentResourceGenerator)->generate($blueprint);
        $resourceContent = File::get($path);
        $formContent = File::get(GenerationPathResolver::resourceSchemaFile('Comment', 'Form'));
        $tableContent = File::get(GenerationPathResolver::resourceTableFile('Comment'));

        // Resource file is thin — no inline components
        expect($resourceContent)
            ->not->toContain("Forms\Components\Select::make('user_id')")
            ->not->toContain("Tables\Columns\TextColumn::make('user.name')");

        // Form file has the Select components
        expect($formContent)
            ->toContain("Forms\Components\Select::make('user_id')")
            ->toContain("->relationship('user', 'name')")
            ->toContain("Forms\Components\Select::make('post_id')")
            ->toContain("->relationship('post', 'title')")
            ->toContain('->required()');

        // Table file has the TextColumn relationship columns
        expect($tableContent)
            ->toContain("Tables\Columns\TextColumn::make('user.name')")
            ->toContain("Tables\Columns\TextColumn::make('post.title')");
    });

    it('falls back to the related key in the form file when no title attribute can be inferred', function () {
        $blueprint = BlueprintData::fromArray([
            'table_name' => 'comments',
            'model_name' => 'Comment',
            'columns' => [
                ['name' => 'post_id', 'type' => 'foreignId'],
            ],
            'gen_resource' => true,
        ]);

        (new FilamentResourceGenerator)->generate($blueprint);

        $formContent = File::get(GenerationPathResolver::resourceSchemaFile('Comment', 'Form'));
        $tableContent = File::get(GenerationPathResolver::resourceTableFile('Comment'));

        expect($formContent)->toContain("->relationship('post', 'id')");
        expect($tableContent)->toContain("Tables\\Columns\\TextColumn::make('post.id')");
    });

    it('generates relationship columns in the table file with the inferred display attribute', function () {
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

        (new FilamentResourceGenerator)->generate($blueprint);

        $tableContent = File::get(GenerationPathResolver::resourceTableFile('Post'));

        expect($tableContent)
            ->toContain("Tables\Columns\TextColumn::make('author.name')")
            ->toContain("->label('Author')")
            ->toContain('->sortable()')
            ->toContain('->searchable()');
    });

    it('merges new columns into separate schema and table files without removing customizations', function () {
        $modelName = 'Project';
        $resourceName = 'ProjectResource';

        $resourcePath = GenerationPathResolver::resource($resourceName);
        $formPath = GenerationPathResolver::resourceSchemaFile($modelName, 'Form');
        $infolistPath = GenerationPathResolver::resourceSchemaFile($modelName, 'Infolist');
        $tablePath = GenerationPathResolver::resourceTableFile($modelName);
        $resourceDir = GenerationPathResolver::resourceDirectory($resourceName);

        $domainNs = testResourcesNamespace().'\\Projects';
        $schemasNs = $domainNs.'\\Schemas';
        $tablesNs = $domainNs.'\\Tables';

        // Create directory structure
        File::ensureDirectoryExists(dirname($resourcePath));
        File::ensureDirectoryExists(dirname($formPath));
        File::ensureDirectoryExists(dirname($tablePath));
        File::makeDirectory("$resourceDir/Pages", 0755, true, true);

        // Thin resource (already exists — will be thin-merged)
        File::put($resourcePath, <<<PHP
<?php

namespace $domainNs;

use {$domainNs}\\Pages;
use {$schemasNs}\\ProjectForm;
use {$schemasNs}\\ProjectInfolist;
use {$tablesNs}\\ProjectsTable;
use App\\Testing\\FilamentResourceGenerator\\Models\\Project;
use Filament\\Resources\\Resource;
use Filament\\Schemas\\Schema;
use Filament\\Tables\\Table;

class ProjectResource extends Resource
{
    protected static ?string \$model = Project::class;

    public static function form(Schema \$schema): Schema { return ProjectForm::configure(\$schema); }
    public static function infolist(Schema \$schema): Schema { return ProjectInfolist::configure(\$schema); }
    public static function table(Table \$table): Table { return ProjectsTable::configure(\$table); }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index' => Pages\\ListProjects::route('/'),
            'create' => Pages\\CreateProject::route('/create'),
            'edit' => Pages\\EditProject::route('/{record}/edit'),
            'view' => Pages\\ViewProject::route('/{record}'),
        ];
    }
}
PHP);

        // Existing form file with two fields
        File::put($formPath, <<<PHP
<?php

namespace $schemasNs;

use Filament\\Forms;
use Filament\\Schemas\\Schema;

class ProjectForm
{
    public static function configure(Schema \$schema): Schema
    {
        return \$schema
            ->components([
                Forms\\Components\\TextInput::make('title')->maxLength(120),
                Forms\\Components\\TextInput::make('slug')->required()->unique(ignoreRecord: true),
            ]);
    }
}
PHP);

        // Existing infolist file
        File::put($infolistPath, <<<PHP
<?php

namespace $schemasNs;

use Filament\\Infolists;
use Filament\\Schemas\\Schema;

class ProjectInfolist
{
    public static function configure(Schema \$schema): Schema
    {
        return \$schema
            ->components([
                \\Filament\\Infolists\\Components\\TextEntry::make('title'),
                \\Filament\\Infolists\\Components\\TextEntry::make('slug'),
            ]);
    }
}
PHP);

        // Existing table file
        File::put($tablePath, <<<PHP
<?php

namespace $tablesNs;

use Filament\\Actions\\BulkActionGroup;
use Filament\\Actions\\DeleteAction;
use Filament\\Actions\\DeleteBulkAction;
use Filament\\Actions\\EditAction;
use Filament\\Tables;
use Filament\\Tables\\Table;

class ProjectsTable
{
    public static function configure(Table \$table): Table
    {
        return \$table
            ->columns([
                Tables\\Columns\\TextColumn::make('title')->sortable(),
            ])
            ->filters([])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
PHP);

        // Generate with new columns in merge mode
        $blueprint = BlueprintData::fromArray([
            'table_name' => 'projects',
            'model_name' => 'Project',
            'gen_resource' => true,
            'generation_mode' => 'merge',
            'columns' => [
                ['name' => 'user_id', 'type' => 'foreignId'],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'slug', 'type' => 'string', 'is_unique' => true],
                ['name' => 'content', 'type' => 'text'],
            ],
        ]);

        (new FilamentResourceGenerator)->generate($blueprint);

        $formContent = File::get($formPath);
        $infolistContent = File::get($infolistPath);
        $tableContent = File::get($tablePath);

        // Form: new fields added in generated order, existing preserved
        expect($formContent)
            ->toContain("Forms\Components\Select::make('user_id')")   // new FK field
            ->toContain("Forms\Components\TextInput::make('title')")   // existing
            ->toContain("Forms\Components\TextInput::make('slug')")    // existing
            ->toContain("Forms\Components\Textarea::make('content')"); // new text field

        // Infolist: new entries merged, existing preserved
        expect($infolistContent)
            ->toContain("TextEntry::make('user_id')")   // new
            ->toContain("TextEntry::make('title')")     // existing
            ->toContain("TextEntry::make('slug')")      // existing
            ->toContain("TextEntry::make('content')");  // new

        // Table: existing column preserved, new FK column added
        expect($tableContent)
            ->toContain("Tables\Columns\TextColumn::make('title')")    // existing
            ->toContain("Tables\Columns\TextColumn::make('user.id')"); // new FK column
    });

    it('preserves existing page classes while creating missing ones in the domain folder', function () {
        $resourceDir = GenerationPathResolver::resourceDirectory('ProjectResource');
        File::ensureDirectoryExists("$resourceDir/Pages");
        File::put("$resourceDir/Pages/ListProjects.php", <<<'PHP'
<?php

namespace App\Testing\FilamentResourceGenerator\Filament\Resources\Projects\Pages;

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

        expect(File::get("$resourceDir/Pages/ListProjects.php"))->toContain("return 'keep-me';")
            ->and(File::exists("$resourceDir/Pages/CreateProject.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/EditProject.php"))->toBeTrue()
            ->and(File::exists("$resourceDir/Pages/ViewProject.php"))->toBeTrue();
    });

    it('removes stale managed fields from schema and table files while keeping custom items', function () {
        $modelName = 'Project';
        $formPath = GenerationPathResolver::resourceSchemaFile($modelName, 'Form');
        $infolistPath = GenerationPathResolver::resourceSchemaFile($modelName, 'Infolist');
        $tablePath = GenerationPathResolver::resourceTableFile($modelName);

        $schemasNs = testResourcesNamespace().'\\Projects\\Schemas';
        $tablesNs = testResourcesNamespace().'\\Projects\\Tables';

        File::ensureDirectoryExists(dirname($formPath));
        File::ensureDirectoryExists(dirname($tablePath));

        // Form with managed + custom components
        File::put($formPath, <<<PHP
<?php

namespace $schemasNs;

use Filament\\Forms;
use Filament\\Schemas\\Schema;

class ProjectForm
{
    public static function configure(Schema \$schema): Schema
    {
        return \$schema
            ->components([
                Forms\\Components\\TextInput::make('title')->required(),
                Forms\\Components\\Textarea::make('content')->required(),
                Forms\\Components\\Textarea::make('excerpt'),
                \\App\\Filament\\Forms\\Components\\SeoPreview::make('seo_preview'),
            ]);
    }
}
PHP);

        // Infolist with managed + custom entries
        File::put($infolistPath, <<<PHP
<?php

namespace $schemasNs;

use Filament\\Infolists;
use Filament\\Schemas\\Schema;

class ProjectInfolist
{
    public static function configure(Schema \$schema): Schema
    {
        return \$schema
            ->components([
                \\Filament\\Infolists\\Components\\TextEntry::make('title'),
                \\Filament\\Infolists\\Components\\TextEntry::make('content'),
                \\Filament\\Infolists\\Components\\TextEntry::make('excerpt'),
                \\App\\Filament\\Infolists\\Components\\AuditEntry::make('audit_log'),
            ]);
    }
}
PHP);

        // Table with managed + custom columns
        File::put($tablePath, <<<PHP
<?php

namespace $tablesNs;

use Filament\\Actions\\BulkActionGroup;
use Filament\\Actions\\DeleteAction;
use Filament\\Actions\\DeleteBulkAction;
use Filament\\Actions\\EditAction;
use Filament\\Tables;
use Filament\\Tables\\Table;

class ProjectsTable
{
    public static function configure(Table \$table): Table
    {
        return \$table
            ->columns([
                Tables\\Columns\\TextColumn::make('title')->sortable()->searchable(),
                Tables\\Columns\\TextColumn::make('content'),
                Tables\\Columns\\TextColumn::make('excerpt'),
                \\App\\Filament\\Tables\\Columns\\StatusBadgeColumn::make('status_label'),
            ])
            ->filters([])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
PHP);

        // Generate with only 'title' — stale 'content' and 'excerpt' should be removed
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

        $formContent = File::get($formPath);
        $infolistContent = File::get($infolistPath);
        $tableContent = File::get($tablePath);

        // Form: stale managed fields removed, custom kept, title kept
        expect($formContent)->toContain("Forms\\Components\\TextInput::make('title')")
            ->and($formContent)->not->toContain("Forms\\Components\\Textarea::make('content')")
            ->and($formContent)->not->toContain("Forms\\Components\\Textarea::make('excerpt')")
            ->and($formContent)->toContain("\\App\\Filament\\Forms\\Components\\SeoPreview::make('seo_preview')");

        // Infolist: stale entries removed, custom kept, title kept
        expect($infolistContent)->toContain("TextEntry::make('title')")
            ->and($infolistContent)->not->toContain("TextEntry::make('content')")
            ->and($infolistContent)->not->toContain("TextEntry::make('excerpt')")
            ->and($infolistContent)->toContain("\\App\\Filament\\Infolists\\Components\\AuditEntry::make('audit_log')");

        // Table: stale columns removed, custom kept, title kept
        expect($tableContent)->toContain("Tables\\Columns\\TextColumn::make('title')")
            ->and($tableContent)->not->toContain("Tables\\Columns\\TextColumn::make('content')")
            ->and($tableContent)->not->toContain("Tables\\Columns\\TextColumn::make('excerpt')")
            ->and($tableContent)->toContain("\\App\\Filament\\Tables\\Columns\\StatusBadgeColumn::make('status_label')");
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

        (new FilamentResourceGenerator)->generate($blueprint);

        $formContent = File::get(GenerationPathResolver::resourceSchemaFile('Comment', 'Form'));
        $tableContent = File::get(GenerationPathResolver::resourceTableFile('Comment'));

        expect($formContent)->toContain("->relationship('post', 'headline')");
        expect($tableContent)->toContain("Tables\\Columns\\TextColumn::make('post.headline')");
    });

    it('keeps the foreign key field name in the form file while using the selected relationship title column', function () {
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

        (new FilamentResourceGenerator)->generate($blueprint);

        $formContent = File::get(GenerationPathResolver::resourceSchemaFile('Post', 'Form'));
        $tableContent = File::get(GenerationPathResolver::resourceTableFile('Post'));

        expect($formContent)
            ->toContain("Forms\\Components\\Select::make('author_id')")
            ->toContain("->relationship('author', 'name')")
            ->not->toContain("Forms\\Components\\Select::make('author.name')");

        expect($tableContent)->toContain("Tables\\Columns\\TextColumn::make('author.name')");
    });
});

// ---------------------------------------------------------------------------
// v4 preview — preview() method and individual previewX() aliases
// ---------------------------------------------------------------------------

describe('v4 preview', function () {
    beforeEach(fn () => useFilamentV4());

    it('preview() concatenates all four generated files with path headers', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $preview = (new FilamentResourceGenerator)->preview($blueprint);

        expect($preview)
            // All four file headers must appear
            ->toContain('ProjectResource.php')
            ->toContain('Schemas/ProjectForm.php')
            ->toContain('Schemas/ProjectInfolist.php')
            ->toContain('Tables/ProjectsTable.php')
            // Form field appears in Form section, not inline in thin resource
            ->toContain("Forms\\Components\\TextInput::make('title')")
            // Table column appears in Table section
            ->toContain("Tables\\Columns\\TextColumn::make('title')")
            // Thin resource delegates to form/table classes
            ->toContain('ProjectForm::configure($schema)');
    });

    it('preview() does not contain Schemas headers for v3', function () {
        useFilamentV3();

        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $preview = (new FilamentResourceGenerator)->preview($blueprint);

        // v3: monolithic — inline content, no multi-file headers
        expect($preview)
            ->toContain("Forms\\Components\\TextInput::make('title')")
            ->toContain("Tables\\Columns\\TextColumn::make('title')")
            ->not->toContain('Schemas/ProjectForm.php');
    });

    it('previewResource() returns only the thin resource content', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $content = (new FilamentResourceGenerator)->previewResource($blueprint);

        expect($content)
            ->toContain('class ProjectResource extends Resource')
            ->toContain('ProjectForm::configure($schema)')
            // No inline field definitions — those live in Form/Table files
            ->not->toContain("TextInput::make('title')");
    });

    it('previewForm() returns only the form schema file content', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $content = (new FilamentResourceGenerator)->previewForm($blueprint);

        expect($content)
            ->toContain('class ProjectForm')
            ->toContain("Forms\\Components\\TextInput::make('title')")
            // Must NOT contain table-specific code
            ->not->toContain('TextColumn');
    });

    it('previewInfolist() returns only the infolist schema file content', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $content = (new FilamentResourceGenerator)->previewInfolist($blueprint);

        expect($content)
            ->toContain('class ProjectInfolist')
            ->toContain("TextEntry::make('title')")
            ->not->toContain('TextInput');
    });

    it('previewTable() returns only the table file content', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $content = (new FilamentResourceGenerator)->previewTable($blueprint);

        expect($content)
            ->toContain('class ProjectsTable')
            ->toContain("Tables\\Columns\\TextColumn::make('title')")
            ->not->toContain('TextInput');
    });

    it('preview() uses plural-studly model name for the Table file header', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'BlogPost',
            'table_name' => 'blog_posts',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        $preview = (new FilamentResourceGenerator)->preview($blueprint);

        expect($preview)
            ->toContain('Tables/BlogPostsTable.php')
            ->toContain('BlogPostResource.php');
    });

    it('preview() includes soft-delete content in Table section for v4', function () {
        $blueprint = BlueprintData::fromArray([
            'model_name' => 'Project',
            'table_name' => 'projects',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'soft_deletes' => true,
            'gen_resource' => true,
        ]);

        $preview = (new FilamentResourceGenerator)->preview($blueprint);

        expect($preview)
            // Soft-delete filter lives in Table file for v4
            ->toContain('TrashedFilter::make()')
            // Soft-delete query lives in thin resource
            ->toContain('getEloquentQuery');
    });
});

// ---------------------------------------------------------------------------
// Legacy v3 artifact classification (smart auto-delete)
// ---------------------------------------------------------------------------

describe('classifyLegacyV3Artifacts()', function () {
    beforeEach(fn () => useFilamentV4());

    it('returns empty when no legacy v3 files exist', function () {
        $blueprint = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => []]);

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['deletable'])->toBe([])
            ->and($result['modified'])->toBe([]);
    });

    it('classifies an unmodified resource file as deletable', function () {
        $blueprint = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);

        // Generate v3 content and write it to the legacy flat location.
        config()->set('architect.filament_version', 'v3');
        $v3Content = (new FilamentResourceGenerator)->previewResource($blueprint);
        config()->set('architect.filament_version', 'v4');

        $legacyFile = GenerationPathResolver::legacyV3Resource('PostResource');
        File::ensureDirectoryExists(dirname($legacyFile));
        File::put($legacyFile, $v3Content);

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['deletable'])->toContain($legacyFile)
            ->and($result['modified'])->not->toContain($legacyFile);
    });

    it('classifies a resource file as deletable even when blueprint columns changed', function () {
        // Blueprint v1 — generated and stored as legacy
        $blueprintV1 = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);

        config()->set('architect.filament_version', 'v3');
        $v3Content = (new FilamentResourceGenerator)->previewResource($blueprintV1);
        config()->set('architect.filament_version', 'v4');

        $legacyFile = GenerationPathResolver::legacyV3Resource('PostResource');
        File::ensureDirectoryExists(dirname($legacyFile));
        File::put($legacyFile, $v3Content);

        // Blueprint v2 — user added a new column; v3 file still has old columns
        $blueprintV2 = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
        ]]);

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprintV2);

        expect($result['deletable'])->toContain($legacyFile)
            ->and($result['modified'])->not->toContain($legacyFile);
    });

    it('classifies a customised resource file as modified', function () {
        $blueprint = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => []]);

        $legacyFile = GenerationPathResolver::legacyV3Resource('PostResource');
        File::ensureDirectoryExists(dirname($legacyFile));
        File::put($legacyFile, '<?php // my custom code that differs from generated output');

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['modified'])->toContain($legacyFile)
            ->and($result['deletable'])->not->toContain($legacyFile);
    });

    it('classifies unmodified pages as deletable when generated by Architect in v3 mode', function () {
        $blueprint = BlueprintData::fromArray([
            'table_name' => 'posts',
            'model_name' => 'Post',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'gen_resource' => true,
        ]);

        // Generate all v3 resource files at the flat location.
        config()->set('architect.filament_version', 'v3');
        (new FilamentResourceGenerator)->generate($blueprint);
        config()->set('architect.filament_version', 'v4');

        $legacyDir = GenerationPathResolver::legacyV3ResourceDirectory('PostResource');
        $listPage = "{$legacyDir}/Pages/ListPosts.php";

        expect(File::exists($listPage))->toBeTrue();

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['deletable'])->toContain($listPage)
            ->and($result['modified'])->not->toContain($listPage);
    });

    it('classifies a customised page file as modified', function () {
        $blueprint = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => []]);

        $legacyDir = GenerationPathResolver::legacyV3ResourceDirectory('PostResource');
        $pagesDir = "{$legacyDir}/Pages";

        File::ensureDirectoryExists($pagesDir);
        File::put("{$pagesDir}/ListPosts.php", '<?php // custom list page code that differs');

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['modified'])->toContain("{$pagesDir}/ListPosts.php")
            ->and($result['deletable'])->not->toContain("{$pagesDir}/ListPosts.php");
    });

    it('classifies unknown files in the legacy directory as modified', function () {
        $blueprint = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => []]);

        $legacyDir = GenerationPathResolver::legacyV3ResourceDirectory('PostResource');
        $pagesDir = "{$legacyDir}/Pages";

        File::ensureDirectoryExists($pagesDir);
        File::put("{$pagesDir}/CustomHelper.php", '<?php // some custom helper file');

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['modified'])->toContain("{$pagesDir}/CustomHelper.php");
    });

    it('returns empty in v3 mode (nothing to migrate)', function () {
        useFilamentV3();

        $blueprint = BlueprintData::fromArray(['table_name' => 'posts', 'model_name' => 'Post', 'columns' => []]);

        $legacyFile = GenerationPathResolver::legacyV3Resource('PostResource');
        File::ensureDirectoryExists(dirname($legacyFile));
        File::put($legacyFile, '<?php // legacy');

        $result = FilamentResourceGenerator::classifyLegacyV3Artifacts($blueprint);

        expect($result['deletable'])->toBe([])
            ->and($result['modified'])->toBe([]);
    });

    it('v3 flat path and v4 domain path differ in default config', function () {
        $legacyFile = GenerationPathResolver::legacyV3Resource('PostResource');
        $v4File = GenerationPathResolver::resource('PostResource');

        expect($legacyFile)->not->toBe($v4File);
    });
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function useFilamentV3(): void
{
    config()->set('architect.filament_version', 'v3');
}

function useFilamentV4(): void
{
    config()->set('architect.filament_version', 'v4');
}

function testResourcesNamespace(): string
{
    return 'App\\Testing\\FilamentResourceGenerator\\Filament\\Resources';
}

function testModelsNamespace(): string
{
    return 'App\\Testing\\FilamentResourceGenerator\\Models';
}

/**
 * Always returns the Filament directory so cleanup works for both v3 and v4.
 *
 * v3: Resources/ProjectResource.php is directly inside Filament/Resources/
 * v4: Resources/Projects/ProjectResource.php is inside Filament/Resources/Projects/
 *
 * Deleting the Filament/ directory covers both layouts.
 */
function testResourcesRoot(): string
{
    return app_path('Testing/FilamentResourceGenerator/Filament');
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
