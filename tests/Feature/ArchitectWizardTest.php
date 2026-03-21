<?php

namespace Lartisan\Architect\Tests\Feature;

use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Generators\MigrationGenerator;
use Lartisan\Architect\Generators\ModelGenerator;
use Lartisan\Architect\Livewire\ArchitectWizard;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Models\BlueprintRevision;
use Lartisan\Architect\Support\GenerationPathResolver;
use Lartisan\Architect\Tests\TestCase;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Livewire\Livewire;

class ArchitectWizardTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(GenerationPathResolver::model('Comment'));
        File::delete(GenerationPathResolver::model('Tag'));
        File::delete(GenerationPathResolver::factory('CommentFactory'));
        File::delete(GenerationPathResolver::seeder('CommentSeeder'));
        File::delete(GenerationPathResolver::resource('CommentResource'));

        $resourceDir = GenerationPathResolver::resourceDirectory('CommentResource');
        if (File::isDirectory($resourceDir)) {
            File::deleteDirectory($resourceDir);
        }

        if (Schema::hasTable('comments')) {
            Schema::drop('comments');
        }

        if (Schema::hasTable('tags')) {
            Schema::drop('tags');
        }

        foreach (File::glob(database_path('migrations/*_tags_table.php')) as $migration) {
            File::delete($migration);
        }

        DB::table('migrations')
            ->where('migration', 'like', '%_tags_table')
            ->delete();

        parent::tearDown();
    }

    /** @test */
    public function it_can_assist_in_creating_a_blueprint()
    {
        Livewire::test(ArchitectWizard::class)
            ->assertActionExists('openArchitect');
    }

    /** @test */
    public function it_can_open_the_architect_action_from_the_modal_host()
    {
        $component = Livewire::test(ArchitectWizard::class)
            ->call('openArchitect');

        $this->assertSame('openArchitect', data_get($component->instance()->mountedActions, '0.name'));
    }

    /** @test */
    public function it_can_save_a_blueprint_to_the_database()
    {
        $data = [
            'table_name' => 'posts',
            'model_name' => 'Post',
            'primary_key_type' => 'id',
            'soft_deletes' => false,
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
            'allow_destructive_changes' => true,
            'allow_likely_renames' => true,
            'run_migration' => false,
        ];

        Livewire::test(ArchitectWizard::class)
            ->callAction('openArchitect', $data)
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('architect_blueprints', [
            'table_name' => 'posts',
            'model_name' => 'Post',
        ]);

        $blueprint = Blueprint::firstWhere('table_name', 'posts');

        $this->assertSame('merge', $blueprint?->meta['generation_mode']);
        $this->assertTrue((bool) ($blueprint?->meta['allow_destructive_changes'] ?? false));
        $this->assertTrue((bool) ($blueprint?->meta['allow_likely_renames'] ?? false));
    }

    /** @test */
    public function it_can_list_existing_blueprints()
    {
        Blueprint::create([
            'table_name' => 'products',
            'model_name' => 'Product',
            'primary_key_type' => 'id',
            'columns' => [],
            'soft_deletes' => false,
        ]);

        Livewire::test(ArchitectWizard::class)
            ->assertSuccessful();
    }

    /** @test */
    public function it_can_load_a_blueprint()
    {
        $blueprint = Blueprint::create([
            'table_name' => 'comments',
            'model_name' => 'Comment',
            'primary_key_type' => 'id',
            'columns' => [
                [
                    'name' => 'body',
                    'type' => 'text',
                    'default' => null,
                    'is_nullable' => false,
                    'is_unique' => false,
                    'is_index' => false,
                ],
            ],
            'soft_deletes' => true,
            'meta' => [
                'gen_factory' => true,
                'gen_seeder' => false,
                'gen_resource' => true,
                'generation_mode' => 'replace',
                'allow_destructive_changes' => true,
                'allow_likely_renames' => true,
            ],
        ]);

        $component = Livewire::test(ArchitectWizard::class)
            ->call('loadBlueprint', $blueprint->id)
            ->assertNotified('Blueprint loaded!');

        $this->assertEquals('comments', $component->instance()->mountedActionData['table_name']);
        $this->assertEquals('Comment', $component->instance()->mountedActionData['model_name']);
        $this->assertTrue($component->instance()->mountedActionData['soft_deletes']);
        $this->assertTrue($component->instance()->mountedActionData['gen_factory']);
        $this->assertFalse($component->instance()->mountedActionData['gen_seeder']);
        $this->assertSame('replace', $component->instance()->mountedActionData['generation_mode']);
        $this->assertTrue($component->instance()->mountedActionData['allow_destructive_changes']);
        $this->assertTrue($component->instance()->mountedActionData['allow_likely_renames']);
    }

    /** @test */
    public function it_can_delete_a_blueprint()
    {
        $blueprintData = [
            'table_name' => 'tags',
            'model_name' => 'Tag',
            'columns' => [
                [
                    'name' => 'name',
                    'type' => 'string',
                ],
            ],
            'gen_factory' => false,
            'gen_seeder' => false,
            'gen_resource' => false,
            'run_migration' => true,
        ];

        $migrationPath = app(MigrationGenerator::class)->generate(BlueprintData::fromArray($blueprintData));
        $modelPath = app(ModelGenerator::class)->generate(BlueprintData::fromArray($blueprintData));

        Artisan::call('migrate', ['--path' => 'database/migrations']);

        $blueprint = Blueprint::create([
            'table_name' => 'tags',
            'model_name' => 'Tag',
            'primary_key_type' => 'id',
            'columns' => [
                [
                    'name' => 'name',
                    'type' => 'string',
                ],
            ],
            'soft_deletes' => false,
        ]);

        Livewire::test(ArchitectWizard::class)
            ->call('deleteBlueprint', $blueprint->id)
            ->assertNotified('Blueprint deleted!');

        $this->assertDatabaseMissing('architect_blueprints', [
            'id' => $blueprint->id,
        ]);

        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertFileExists($modelPath);
        $this->assertFileExists($migrationPath);
    }

    /** @test */
    public function it_records_a_blueprint_revision_after_successful_generation()
    {
        $data = [
            'table_name' => 'posts',
            'model_name' => 'Post',
            'primary_key_type' => 'id',
            'soft_deletes' => false,
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
            'allow_destructive_changes' => false,
            'allow_likely_renames' => false,
            'run_migration' => false,
        ];

        Livewire::test(ArchitectWizard::class)
            ->callAction('openArchitect', $data)
            ->assertHasNoActionErrors();

        $blueprint = Blueprint::firstWhere('table_name', 'posts');
        $revision = BlueprintRevision::query()->where('blueprint_id', $blueprint?->id)->latest('revision')->first();

        $this->assertNotNull($revision);
        $this->assertSame('posts', $revision?->snapshot['table_name'] ?? null);
        $this->assertSame('title', $revision?->snapshot['columns'][0]['name'] ?? null);
    }

    /** @test */
    public function it_halts_generation_when_adding_a_required_column_without_a_default_to_a_populated_table()
    {
        Schema::create('comments', function (SchemaBlueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        DB::table('comments')->insert([
            'user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = [
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
        ];

        Livewire::test(ArchitectWizard::class)
            ->callAction('openArchitect', $data);

        $this->assertDatabaseMissing('architect_blueprints', [
            'table_name' => 'comments',
            'model_name' => 'Comment',
        ]);

        $this->assertNull(BlueprintRevision::query()->first());
        $this->assertFileDoesNotExist(GenerationPathResolver::model('Comment'));
        $this->assertFileDoesNotExist(GenerationPathResolver::resource('CommentResource'));
    }
}
