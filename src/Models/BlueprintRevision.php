<?php

namespace Lartisan\Architect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\BlueprintRevisionSnapshot;

class BlueprintRevision extends Model
{
    protected $table = 'architect_blueprint_revisions';

    protected $fillable = ['blueprint_id', 'revision', 'snapshot_version', 'snapshot', 'meta'];

    protected $casts = [
        'snapshot' => 'array',
        'meta' => 'array',
    ];

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class, 'blueprint_id');
    }

    public function toBlueprintData(): BlueprintData
    {
        return $this->toSnapshot()->toBlueprintData();
    }

    public function toSnapshot(): BlueprintRevisionSnapshot
    {
        return BlueprintRevisionSnapshot::fromRevision($this);
    }
}
