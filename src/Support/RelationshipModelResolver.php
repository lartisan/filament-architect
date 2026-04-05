<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lartisan\Architect\Models\Blueprint;
use Lartisan\Architect\ValueObjects\ColumnDefinition;

class RelationshipModelResolver
{
    public function resolveModelName(ColumnDefinition $column, ?string $relationshipName = null): string
    {
        if (filled($column->relationshipTable)) {
            return $this->resolveModelNameFromTable((string) $column->relationshipTable);
        }

        if (filled($relationshipName)) {
            return Str::studly((string) $relationshipName);
        }

        return Str::studly($column->name);
    }

    private function resolveModelNameFromTable(string $tableName): string
    {
        $modelName = $this->resolveBlueprintModelName($tableName);

        if (filled($modelName)) {
            return (string) $modelName;
        }

        return Str::studly(Str::singular($tableName));
    }

    private function resolveBlueprintModelName(string $tableName): ?string
    {
        $blueprintTable = (new Blueprint)->getTable();

        if (! Schema::hasTable($blueprintTable)) {
            return null;
        }

        return Blueprint::query()
            ->where('table_name', $tableName)
            ->value('model_name');
    }
}
