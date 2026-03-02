<?php

namespace Lartisan\Architect\Tests\Feature;

use Lartisan\Architect\Livewire\ArchitectWizard;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\Tests\TestCase;
use Livewire\Livewire;

class ArchitectWizardTest extends TestCase
{
    /** @test */
    public function it_can_assist_in_creating_a_blueprint()
    {
        Livewire::test(ArchitectWizard::class)
            ->assertActionExists('openArchitect');
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
            'run_migration' => false,
        ];

        Livewire::test(ArchitectWizard::class)
            ->callAction('openArchitect', $data)
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('architect_blueprints', [
            'table_name' => 'posts',
            'model_name' => 'Post',
        ]);
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
    }

    /** @test */
    public function it_can_delete_a_blueprint()
    {
        $blueprint = Blueprint::create([
            'table_name' => 'tags',
            'model_name' => 'Tag',
            'primary_key_type' => 'id',
            'columns' => [],
            'soft_deletes' => false,
        ]);

        Livewire::test(ArchitectWizard::class)
            ->call('deleteBlueprint', $blueprint->id)
            ->assertNotified('Blueprint deleted!');

        $this->assertDatabaseMissing('architect_blueprints', [
            'id' => $blueprint->id,
        ]);
    }
}
