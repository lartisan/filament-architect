<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\File;
use Lartisan\Architect\ValueObjects\BlueprintData;

readonly class MigrationGenerator extends AbstractGenerator
{
    protected function getContent(BlueprintData $blueprint): string
    {
        $stub = $this->getStub('migration');

        $dropStatement = $blueprint->overwriteTable
            ? "Schema::dropIfExists('{$blueprint->tableName}');"
            : '// New table';

        return $this->replacePlaceholders($stub, [
            '{{ table_name }}' => $blueprint->tableName,
            '{{ drop_statement }}' => $dropStatement,
            '{{ columns }}' => $this->buildColumnsString($blueprint),
        ]);
    }

    public function generate(BlueprintData $blueprint): string
    {
        $filename = date('Y_m_d_His')."_create_{$blueprint->tableName}_table.php";
        $path = database_path("migrations/{$filename}");

        $this->ensureDirectoryExists($path);

        File::put($path, $this->getContent($blueprint));

        return $path;
    }

    protected function buildColumnsString(BlueprintData $blueprint): string
    {
        $lines = [];

        $lines[] = match ($blueprint->primaryKeyType) {
            'uuid' => '$table->uuid(\'id\')->primary();',
            'ulid' => '$table->ulid(\'id\')->primary();',
            default => '$table->id();',
        };

        foreach ($blueprint->columns as $column) {
            $lines[] = $column->toMigrationLine();
        }

        $lines[] = '$table->timestamps();';

        if ($blueprint->softDeletes) {
            $lines[] = '$table->softDeletes();';
        }

        return implode("\n            ", $lines);
    }
}
