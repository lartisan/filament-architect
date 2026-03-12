<?php

namespace Lartisan\Architect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lartisan\Architect\ValueObjects\BlueprintData;

class BlueprintRevision extends Model
{
    protected $table = 'architect_blueprint_revisions';

    protected $fillable = ['blueprint_id', 'revision', 'snapshot'];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class, 'blueprint_id');
    }

    public function toBlueprintData(): BlueprintData
    {
        return BlueprintData::fromArray($this->snapshot ?? [], shouldValidate: false);
    }
}
