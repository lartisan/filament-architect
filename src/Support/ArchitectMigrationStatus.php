<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ArchitectMigrationStatus
{
    /**
     * @return array<int, string>
     */
    public function missingTables(): array
    {
        return array_values(array_filter([
            'architect_blueprints',
            'architect_blueprint_revisions',
        ], fn (string $table): bool => ! $this->hasTable($table)));
    }

    public function isReady(): bool
    {
        return $this->missingTables() === [];
    }

    public function hasStoredRevisions(): bool
    {
        if (! $this->isReady()) {
            return false;
        }

        try {
            return DB::table('architect_blueprint_revisions')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    protected function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}


