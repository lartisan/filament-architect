<?php

use Filament\Actions\Action;
use Filament\Schemas\Components\Tabs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\ArchitectPlugin;
use Lartisan\Architect\Contracts\ArchitectBlockProvider;
use Lartisan\Architect\Support\ArchitectBlockRegistry;
use Lartisan\Architect\Support\ArchitectCapabilityRegistry;
use Lartisan\Architect\Support\ArchitectUiExtensionRegistry;
use Lartisan\Architect\Support\BlueprintGenerationHookRegistry;
use Lartisan\Architect\Support\BlueprintGenerationService;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;

uses(TestCase::class);

beforeEach(function () {
    app(ArchitectCapabilityRegistry::class)->flush();
    app(ArchitectBlockRegistry::class)->flush();
    app(ArchitectUiExtensionRegistry::class)->flush();
    app(BlueprintGenerationHookRegistry::class)->flush();
});

afterEach(function () {
    app(ArchitectCapabilityRegistry::class)->flush();
    app(ArchitectBlockRegistry::class)->flush();
    app(ArchitectUiExtensionRegistry::class)->flush();
    app(BlueprintGenerationHookRegistry::class)->flush();

    File::delete(GenerationPathResolver::model('ExtensionPost'));
    File::delete(GenerationPathResolver::factory('ExtensionPostFactory'));
    File::delete(GenerationPathResolver::seeder('ExtensionPostSeeder'));
    File::delete(GenerationPathResolver::resource('ExtensionPostResource'));

    $resourceDirectory = GenerationPathResolver::resourceDirectory('ExtensionPostResource');

    if (File::isDirectory($resourceDirectory)) {
        File::deleteDirectory($resourceDirectory);
    }

    foreach (File::glob(database_path('migrations/*_extension_posts_table.php')) as $migrationFile) {
        File::delete($migrationFile);
    }

    DB::table('migrations')
        ->where('migration', 'like', '%_extension_posts_table')
        ->delete();

    if (Schema::hasTable('extension_posts')) {
        Schema::drop('extension_posts');
    }
});

it('resolves architect capabilities through the shared registry', function () {
    $registry = app(ArchitectCapabilityRegistry::class);

    expect($registry->has('premium.blocks'))->toBeFalse();

    $registry->define('premium.blocks', true)
        ->define('premium.revisions.browser', fn (): bool => true);

    expect(ArchitectPlugin::capabilities()->has('premium.blocks'))->toBeTrue()
        ->and(ArchitectPlugin::capabilities()->has('premium.revisions.browser'))->toBeTrue()
        ->and($registry->all())->toMatchArray([
            'premium.blocks' => true,
            'premium.revisions.browser' => true,
        ]);
});

it('merges registered architect blocks without duplicating existing types', function () {
    $registry = app(ArchitectBlockRegistry::class);

    $registry->register([
        'type' => 'premium-metrics',
        'label' => 'Premium Metrics',
    ])->extend(new class implements ArchitectBlockProvider
    {
        public function blocks(): array
        {
            return [[
                'type' => 'premium-carousel',
                'label' => 'Premium Carousel',
            ]];
        }
    });

    $mergedBlocks = ArchitectPlugin::blocks()->merge([
        [
            'type' => 'hero',
            'label' => 'Hero',
        ],
        [
            'type' => 'premium-carousel',
            'label' => 'Premium Carousel from Base',
        ],
    ]);

    expect(array_column($mergedBlocks, 'type'))
        ->toBe(['hero', 'premium-carousel', 'premium-metrics']);
});

it('stores ui extensions for create, existing resources, extra tabs, and record actions', function () {
    $registry = app(ArchitectUiExtensionRegistry::class);

    $registry->registerCreateEditExtension(fn (): array => ['create-edit-fragment'])
        ->registerExistingResourcesExtension(fn (): array => ['existing-resource-fragment'])
        ->registerTab(fn (): Tabs\Tab => Tabs\Tab::make('Premium'))
        ->registerBlueprintsTableRecordActions(fn (): Action => Action::make('revision_history'));

    expect(ArchitectPlugin::uiExtensions()->createEditExtensions())->toBe(['create-edit-fragment'])
        ->and(ArchitectPlugin::uiExtensions()->existingResourcesExtensions())->toBe(['existing-resource-fragment'])
        ->and(ArchitectPlugin::uiExtensions()->blueprintsTableRecordActions())->toHaveCount(1)
        ->and(ArchitectPlugin::uiExtensions()->blueprintsTableRecordActions()[0])->toBeInstanceOf(Action::class)
        ->and(ArchitectPlugin::uiExtensions()->tabs())->toHaveCount(1)
        ->and(ArchitectPlugin::uiExtensions()->tabs()[0])->toBeInstanceOf(Tabs\Tab::class);
});

it('runs registered post-generation hooks after a blueprint is generated', function () {
    $hookCalls = [];

    ArchitectPlugin::generationHooks()->afterGenerate(function ($blueprint, BlueprintData $blueprintData, $plan, bool $shouldRunMigration) use (&$hookCalls): void {
        $hookCalls[] = [
            'blueprint_id' => $blueprint->id,
            'table_name' => $blueprintData->tableName,
            'should_run_migration' => $shouldRunMigration,
            'plan_has_operations' => count($plan->schemaOperations) >= 0,
        ];
    });

    $blueprintData = BlueprintData::fromArray([
        'table_name' => 'extension_posts',
        'model_name' => 'ExtensionPost',
        'columns' => [
            [
                'name' => 'title',
                'type' => 'string',
            ],
        ],
        'gen_factory' => false,
        'gen_seeder' => false,
        'gen_resource' => false,
        'run_migration' => true,
    ]);

    $result = app(BlueprintGenerationService::class)->generate($blueprintData);

    expect($result['shouldRunMigration'])->toBeTrue()
        ->and($hookCalls)->toHaveCount(1)
        ->and($hookCalls[0]['table_name'])->toBe('extension_posts')
        ->and($hookCalls[0]['should_run_migration'])->toBeTrue();
});
