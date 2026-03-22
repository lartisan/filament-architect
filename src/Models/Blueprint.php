<?php

namespace Lartisan\Architect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\BlueprintRevisionSnapshot;

class Blueprint extends Model
{
    protected $table = 'architect_blueprints';

    protected $fillable = ['model_name', 'table_name', 'primary_key_type', 'columns', 'soft_deletes', 'meta'];

    protected $casts = [
        'columns' => 'array',
        'meta' => 'array',
        'soft_deletes' => 'boolean',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(BlueprintRevision::class, 'blueprint_id')->orderByDesc('revision');
    }

    public function latestRevision(): HasOne
    {
        return $this->hasOne(BlueprintRevision::class, 'blueprint_id')->latestOfMany('revision');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordRevision(BlueprintData $blueprintData, array $meta = []): BlueprintRevision
    {
        $nextRevision = ((int) $this->revisions()->max('revision')) + 1;

        return $this->revisions()->create([
            'revision' => $nextRevision,
            'snapshot_version' => BlueprintRevisionSnapshot::CURRENT_VERSION,
            'snapshot' => $blueprintData->toFormData(),
            'meta' => array_merge($blueprintData->meta, $meta),
        ]);
    }

    public function toFormData(): array
    {
        return [
            'table_name' => $this->table_name,
            'model_name' => $this->model_name,
            'primary_key_type' => $this->primary_key_type,
            'columns' => $this->columns,
            'soft_deletes' => (bool) $this->soft_deletes,
            'gen_factory' => $this->meta['gen_factory'] ?? true,
            'gen_seeder' => $this->meta['gen_seeder'] ?? true,
            'gen_resource' => $this->meta['gen_resource'] ?? true,
            'generation_mode' => $this->meta['generation_mode'] ?? GenerationMode::default()->value,
            'allow_destructive_changes' => (bool) ($this->meta['allow_destructive_changes'] ?? false),
            'allow_likely_renames' => (bool) ($this->meta['allow_likely_renames'] ?? false),
            'run_migration' => false,
            'overwrite_table' => false,
        ];
    }
}
