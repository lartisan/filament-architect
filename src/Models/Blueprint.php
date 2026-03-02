<?php

namespace Lartisan\Architect\Models;

use Illuminate\Database\Eloquent\Model;

class Blueprint extends Model
{
    protected $table = 'architect_blueprints';

    protected $fillable = ['model_name', 'table_name', 'primary_key_type', 'columns', 'soft_deletes', 'meta'];

    protected $casts = [
        'columns' => 'array',
        'meta' => 'array',
        'soft_deletes' => 'boolean',
    ];

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
            'run_migration' => false,
            'overwrite_table' => false,
        ];
    }
}
