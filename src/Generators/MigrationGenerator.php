<?php

namespace Lartisan\Architect\Generators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\Models\Blueprint as ArchitectBlueprint;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\ColumnDefinition;

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
        $existingCreateMigration = $this->findCreateMigrationPath($blueprint->tableName);

        if ($existingCreateMigration !== null && ! $this->hasMigrationRun($existingCreateMigration)) {
            if ($blueprint->generationMode === GenerationMode::Create) {
                return $existingCreateMigration;
            }

            $this->writeFormattedFile($existingCreateMigration, $this->getContent($blueprint));

            return $existingCreateMigration;
        }

        if (Schema::hasTable($blueprint->tableName)) {
            if ($blueprint->generationMode === GenerationMode::Create) {
                return '';
            }

            $syncContent = $this->getSyncContent($blueprint);

            if ($syncContent === null) {
                return '';
            }

            $filename = date('Y_m_d_His')."_sync_{$blueprint->tableName}_table.php";
            $path = database_path("migrations/{$filename}");
            $this->ensureDirectoryExists($path);
            $this->writeFormattedFile($path, $syncContent);

            return $path;
        }

        if ($existingCreateMigration !== null) {
            return $existingCreateMigration;
        }

        $filename = date('Y_m_d_His')."_create_{$blueprint->tableName}_table.php";
        $path = database_path("migrations/{$filename}");

        $this->ensureDirectoryExists($path);
        $this->writeFormattedFile($path, $this->getContent($blueprint));

        return $path;
    }

    public function preview(BlueprintData $blueprint): string
    {
        $revisionPreview = $this->getRevisionPreviewContent($blueprint);

        if ($revisionPreview !== null) {
            return $revisionPreview;
        }

        $additivePreview = $this->getSchemaAdditivePreviewContent($blueprint);

        if ($additivePreview !== null) {
            return $additivePreview;
        }

        return parent::preview($blueprint);
    }

    private function getRevisionPreviewContent(BlueprintData $blueprint): ?string
    {
        $baselineBlueprint = $this->findPreviewBaselineRevision($blueprint);

        if (! $baselineBlueprint instanceof BlueprintData) {
            return null;
        }

        [$upLines, $downLines] = $this->buildPreviewOperationsFromBlueprints($baselineBlueprint, $blueprint);

        if ($upLines === [] && $downLines === []) {
            return $this->buildSyncStubContent($blueprint->tableName, ['// No schema changes detected.'], ['// No schema changes to rollback.']);
        }

        return $this->buildSyncStubContent($blueprint->tableName, $upLines, $downLines);
    }

    private function getSchemaAdditivePreviewContent(BlueprintData $blueprint): ?string
    {
        if (! Schema::hasTable($blueprint->tableName)) {
            return null;
        }

        [$upLines, $downLines] = $this->buildAdditivePreviewOperationsFromSchema($blueprint);

        if ($upLines === [] || $downLines === []) {
            return null;
        }

        return $this->buildSyncStubContent($blueprint->tableName, $upLines, $downLines);
    }

    private function buildPreviewOperationsFromBlueprints(BlueprintData $baselineBlueprint, BlueprintData $blueprint): array
    {
        $upLines = [];
        $downLines = [];
        $orderedColumns = array_values($blueprint->columns);
        $baselineColumns = array_values($baselineBlueprint->columns);
        $baselineByName = collect($baselineColumns)->keyBy(fn (ColumnDefinition $column) => strtolower($column->name));
        $desiredColumns = collect($orderedColumns)->keyBy(fn (ColumnDefinition $column) => strtolower($column->name));
        $removedColumns = collect($baselineColumns)
            ->filter(fn (ColumnDefinition $column) => ! $desiredColumns->has(strtolower($column->name)))
            ->keyBy(fn (ColumnDefinition $column) => strtolower($column->name))
            ->all();
        $addedColumns = collect($orderedColumns)
            ->filter(fn (ColumnDefinition $column) => ! $baselineByName->has(strtolower($column->name)))
            ->keyBy(fn (ColumnDefinition $column) => strtolower($column->name))
            ->all();
        $renamePairs = $blueprint->allowLikelyRenames ? $this->detectLikelyRenamesFromDefinitions($removedColumns, $addedColumns) : [];
        $addedColumnNames = [];
        $knownColumnNames = array_map(fn (ColumnDefinition $column) => strtolower($column->name), $baselineColumns);

        foreach ($orderedColumns as $index => $column) {
            if ($this->isRenameTarget($column->name, $renamePairs)) {
                continue;
            }

            $baselineColumn = $baselineByName->get(strtolower($column->name));

            if (! $baselineColumn instanceof ColumnDefinition) {
                $upLines[] = $this->appendAfterModifier(
                    $column->toMigrationLine(),
                    $this->inferPreviousColumnName($orderedColumns, $index, $knownColumnNames, $addedColumnNames),
                );
                $downLines[] = "\$table->dropColumn('{$column->name}');";
                $this->appendIndexDiffsFromDefinitions($column, null, $upLines, $downLines);
                $addedColumnNames[] = $column->name;

                continue;
            }

            $needsDefaultChange = $baselineColumn->default !== $column->default;

            if (! $this->matchesBlueprintColumnShape($column, $baselineColumn)) {
                $upLines[] = $column->toChangeMigrationLine(forceDefault: $needsDefaultChange);
                $downLines[] = $baselineColumn->toChangeMigrationLine(forceDefault: true);
            }

            $this->appendIndexDiffsFromDefinitions($column, $baselineColumn, $upLines, $downLines);
        }

        foreach ($renamePairs as $from => $to) {
            $upLines[] = "\$table->renameColumn('{$from}', '{$to}');";
            $downLines[] = "\$table->renameColumn('{$to}', '{$from}');";
        }

        if ($blueprint->allowDestructiveChanges) {
            foreach ($removedColumns as $name => $column) {
                if (isset($renamePairs[$name])) {
                    continue;
                }

                $upLines[] = "\$table->dropColumn('{$name}');";
                $downLines[] = $column->toMigrationLine();
            }
        }

        if ($blueprint->softDeletes && ! $baselineBlueprint->softDeletes) {
            $upLines[] = '$table->softDeletes();';
            $downLines[] = '$table->dropSoftDeletes();';
        }

        if (! $blueprint->softDeletes && $baselineBlueprint->softDeletes && $blueprint->allowDestructiveChanges) {
            $upLines[] = '$table->dropSoftDeletes();';
            $downLines[] = '$table->softDeletes();';
        }

        return [$upLines, $downLines];
    }

    private function matchesBlueprintColumnShape(ColumnDefinition $column, ColumnDefinition $baselineColumn): bool
    {
        return $this->normalizeSchemaType($column->type) === $this->normalizeSchemaType($baselineColumn->type)
            && $column->nullable === $baselineColumn->nullable
            && $column->default === $baselineColumn->default;
    }

    private function appendIndexDiffsFromDefinitions(ColumnDefinition $column, ?ColumnDefinition $baselineColumn, array &$upLines, array &$downLines): void
    {
        $baselineUnique = $baselineColumn?->unique ?? false;
        $baselineIndex = ($baselineColumn?->index ?? false) && ! $baselineUnique;
        $desiredIndex = $column->index && ! $column->unique;

        if ($column->unique && ! $baselineUnique) {
            $upLines[] = "\$table->unique('{$column->name}');";
            $downLines[] = "\$table->dropUnique(['{$column->name}']);";
        }

        if (! $column->unique && $baselineUnique) {
            $upLines[] = "\$table->dropUnique(['{$column->name}']);";
            $downLines[] = "\$table->unique('{$column->name}');";
        }

        if ($desiredIndex && ! $baselineIndex) {
            $upLines[] = "\$table->index('{$column->name}');";
            $downLines[] = "\$table->dropIndex(['{$column->name}']);";
        }

        if (! $desiredIndex && $baselineIndex) {
            $upLines[] = "\$table->dropIndex(['{$column->name}']);";
            $downLines[] = "\$table->index('{$column->name}');";
        }
    }

    private function detectLikelyRenamesFromDefinitions(array $removedColumns, array $addedColumns): array
    {
        if (count($removedColumns) !== 1 || count($addedColumns) !== 1) {
            return [];
        }

        $from = array_key_first($removedColumns);
        $to = array_key_first($addedColumns);
        $removedColumn = $removedColumns[$from];
        $addedColumn = $addedColumns[$to];

        if (! $removedColumn instanceof ColumnDefinition || ! $addedColumn instanceof ColumnDefinition) {
            return [];
        }

        if ($this->normalizeSchemaType($removedColumn->type) !== $this->normalizeSchemaType($addedColumn->type)) {
            return [];
        }

        return [$from => $addedColumn->name];
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

    private function findCreateMigrationPath(string $tableName): ?string
    {
        $matches = File::glob(database_path("migrations/*_create_{$tableName}_table.php"));

        return $matches[0] ?? null;
    }

    private function hasMigrationRun(string $path): bool
    {
        return DB::table('migrations')
            ->where('migration', pathinfo($path, PATHINFO_FILENAME))
            ->exists();
    }

    private function getSyncContent(BlueprintData $blueprint): ?string
    {
        $baselineBlueprint = $this->findPreviewBaselineRevision($blueprint);

        [$upLines, $downLines] = $baselineBlueprint instanceof BlueprintData
            ? $this->buildPreviewOperationsFromBlueprints($baselineBlueprint, $blueprint)
            : $this->buildSyncOperations($blueprint);

        if ($upLines === [] && $downLines === []) {
            return null;
        }

        return $this->buildSyncStubContent($blueprint->tableName, $upLines, $downLines);
    }

    private function getAdditivePreviewContent(BlueprintData $blueprint): ?string
    {
        if (! Schema::hasTable($blueprint->tableName)) {
            return null;
        }

        $baselineBlueprint = $this->findPreviewBaselineRevision($blueprint);
        [$upLines, $downLines] = $baselineBlueprint instanceof BlueprintData
            ? $this->buildAdditivePreviewOperationsFromBlueprint($blueprint, $baselineBlueprint)
            : $this->buildAdditivePreviewOperationsFromSchema($blueprint);

        if ($upLines === [] || $downLines === []) {
            return null;
        }

        return $this->buildSyncStubContent($blueprint->tableName, $upLines, $downLines);
    }

    private function findPreviewBaselineRevision(BlueprintData $blueprint): ?BlueprintData
    {
        $storedBlueprint = ArchitectBlueprint::query()
            ->where('table_name', $blueprint->tableName)
            ->with('latestRevision')
            ->first();

        return $storedBlueprint?->latestRevision?->toBlueprintData();
    }

    private function buildAdditivePreviewOperationsFromSchema(BlueprintData $blueprint): array
    {
        $currentColumns = collect(Schema::getColumns($blueprint->tableName))
            ->keyBy(fn (array $column) => strtolower($column['name']));
        $desiredColumns = collect($blueprint->columns)->keyBy(fn ($column) => strtolower($column->name));
        $removedColumns = $this->findRemovedColumns($currentColumns, $desiredColumns, $blueprint->softDeletes);

        if ($removedColumns !== [] || ($blueprint->softDeletes && ! Schema::hasColumn($blueprint->tableName, 'deleted_at')) || (! $blueprint->softDeletes && Schema::hasColumn($blueprint->tableName, 'deleted_at'))) {
            return [[], []];
        }

        $upLines = [];
        $downLines = [];
        $addedColumnNames = [];
        $orderedColumns = array_values($blueprint->columns);
        $knownColumnNames = $currentColumns->keys()->all();

        foreach ($orderedColumns as $index => $column) {
            $currentColumn = $currentColumns->get(strtolower($column->name));

            if (is_array($currentColumn)) {
                if ($this->needsColumnChange($column, $currentColumn)) {
                    return [[], []];
                }

                $currentHasUnique = Schema::hasIndex($blueprint->tableName, [$column->name], 'unique');
                $currentHasIndex = Schema::hasIndex($blueprint->tableName, [$column->name], 'index');
                $desiredIndex = $column->index && ! $column->unique;
                $hasStandaloneIndex = $currentHasIndex && ! $currentHasUnique;

                if ($column->unique !== $currentHasUnique || $desiredIndex !== $hasStandaloneIndex) {
                    return [[], []];
                }

                continue;
            }

            $upLines[] = $this->appendAfterModifier(
                $column->toMigrationLine(),
                $this->inferPreviousColumnName($orderedColumns, $index, $knownColumnNames, $addedColumnNames),
            );
            $downLines[] = "\$table->dropColumn('{$column->name}');";
            $addedColumnNames[] = $column->name;
        }

        return [$upLines, $downLines];
    }

    private function buildAdditivePreviewOperationsFromBlueprint(BlueprintData $blueprint, BlueprintData $baselineBlueprint): array
    {
        if ($blueprint->softDeletes !== $baselineBlueprint->softDeletes) {
            return [[], []];
        }

        $orderedColumns = array_values($blueprint->columns);
        $baselineColumns = array_values($baselineBlueprint->columns);
        $baselineByName = collect($baselineColumns)->keyBy(fn (ColumnDefinition $column) => strtolower($column->name));
        $desiredColumns = collect($orderedColumns)->keyBy(fn (ColumnDefinition $column) => strtolower($column->name));

        foreach ($baselineColumns as $baselineColumn) {
            if (! $desiredColumns->has(strtolower($baselineColumn->name))) {
                return [[], []];
            }
        }

        $upLines = [];
        $downLines = [];
        $addedColumnNames = [];
        $knownColumnNames = array_map(fn (ColumnDefinition $column) => strtolower($column->name), $baselineColumns);

        foreach ($orderedColumns as $index => $column) {
            $baselineColumn = $baselineByName->get(strtolower($column->name));

            if ($baselineColumn instanceof ColumnDefinition) {
                if (! $this->matchesBlueprintColumn($column, $baselineColumn)) {
                    return [[], []];
                }

                continue;
            }

            $upLines[] = $this->appendAfterModifier(
                $column->toMigrationLine(),
                $this->inferPreviousColumnName($orderedColumns, $index, $knownColumnNames, $addedColumnNames),
            );
            $downLines[] = "\$table->dropColumn('{$column->name}');";
            $addedColumnNames[] = $column->name;
        }

        return [$upLines, $downLines];
    }

    private function matchesBlueprintColumn(ColumnDefinition $column, ColumnDefinition $baselineColumn): bool
    {
        return $this->normalizeSchemaType($column->type) === $this->normalizeSchemaType($baselineColumn->type)
            && $column->nullable === $baselineColumn->nullable
            && $column->unique === $baselineColumn->unique
            && $column->index === $baselineColumn->index
            && $column->default === $baselineColumn->default;
    }

    private function inferPreviousColumnName(array $orderedColumns, int $index, array $knownColumnNames, array $addedColumnNames): ?string
    {
        for ($offset = $index - 1; $offset >= 0; $offset--) {
            $previousName = $orderedColumns[$offset]->name;

            if (in_array(strtolower($previousName), $knownColumnNames, true) || in_array($previousName, $addedColumnNames, true)) {
                return $previousName;
            }
        }

        return null;
    }

    private function appendAfterModifier(string $line, ?string $afterColumn): string
    {
        if ($afterColumn === null) {
            return $line;
        }

        return substr($line, 0, -1)."->after('{$afterColumn}');";
    }

    private function buildSyncStubContent(string $tableName, array $upLines, array $downLines): string
    {
        return $this->replacePlaceholders($this->getStub('migration-update'), [
            '{{ table_name }}' => $tableName,
            '{{ columns }}' => implode("\n            ", $upLines),
            '{{ rollback_columns }}' => implode("\n            ", array_reverse($downLines)),
        ]);
    }

    private function buildSyncOperations(BlueprintData $blueprint): array
    {
        $upLines = [];
        $downLines = [];
        $currentColumns = collect(Schema::getColumns($blueprint->tableName))
            ->keyBy(fn (array $column) => strtolower($column['name']));
        $desiredColumns = collect($blueprint->columns)->keyBy(fn ($column) => strtolower($column->name));
        $removedColumns = $this->findRemovedColumns($currentColumns, $desiredColumns, $blueprint->softDeletes);
        $addedColumns = collect($blueprint->columns)
            ->filter(fn ($column) => ! $currentColumns->has(strtolower($column->name)))
            ->keyBy(fn ($column) => strtolower($column->name))
            ->all();
        $renamePairs = $blueprint->allowLikelyRenames ? $this->detectLikelyRenames($removedColumns, $addedColumns) : [];

        foreach ($blueprint->columns as $column) {
            if ($this->isRenameTarget($column->name, $renamePairs)) {
                continue;
            }

            $currentColumn = $currentColumns->get(strtolower($column->name));

            if (! is_array($currentColumn)) {
                $upLines[] = $column->toMigrationLine();
                $downLines[] = "\$table->dropColumn('{$column->name}');";
                $this->appendIndexDiffs($blueprint->tableName, $column, false, false, $upLines, $downLines);

                continue;
            }

            $previousColumn = $this->columnDefinitionFromSchema($currentColumn);
            $needsDefaultChange = $this->normalizeDefaultValue($currentColumn['default']) !== $column->default;

            if ($this->needsColumnChange($column, $currentColumn)) {
                $upLines[] = $column->toChangeMigrationLine(forceDefault: $needsDefaultChange);
                $downLines[] = $previousColumn->toChangeMigrationLine(forceDefault: true);
            }

            $currentHasUnique = Schema::hasIndex($blueprint->tableName, [$column->name], 'unique');
            $currentHasIndex = Schema::hasIndex($blueprint->tableName, [$column->name], 'index');
            $this->appendIndexDiffs($blueprint->tableName, $column, $currentHasUnique, $currentHasIndex, $upLines, $downLines);
        }

        foreach ($renamePairs as $from => $to) {
            $upLines[] = "\$table->renameColumn('{$from}', '{$to}');";
            $downLines[] = "\$table->renameColumn('{$to}', '{$from}');";
        }

        if ($blueprint->allowDestructiveChanges) {
            foreach ($removedColumns as $name => $column) {
                if (isset($renamePairs[$name])) {
                    continue;
                }

                if ($name === 'deleted_at') {
                    $upLines[] = '$table->dropSoftDeletes();';
                    $downLines[] = '$table->softDeletes();';

                    continue;
                }

                $upLines[] = "\$table->dropColumn('{$name}');";
                $downLines[] = $this->columnDefinitionFromSchema($column)->toMigrationLine();
            }
        }

        if ($blueprint->softDeletes && ! Schema::hasColumn($blueprint->tableName, 'deleted_at')) {
            $upLines[] = '$table->softDeletes();';
            $downLines[] = '$table->dropSoftDeletes();';
        }

        return [$upLines, $downLines];
    }

    private function findRemovedColumns($currentColumns, $desiredColumns, bool $softDeletes): array
    {
        $removed = [];

        foreach ($currentColumns as $name => $column) {
            if (in_array($name, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            if ($name === 'deleted_at' && $softDeletes) {
                continue;
            }

            if (! $desiredColumns->has($name) && ($name !== 'deleted_at' || ! $softDeletes)) {
                $removed[$name] = $column;
            }
        }

        return $removed;
    }

    private function detectLikelyRenames(array $removedColumns, array $addedColumns): array
    {
        if (count($removedColumns) !== 1 || count($addedColumns) !== 1) {
            return [];
        }

        $from = array_key_first($removedColumns);
        $to = array_key_first($addedColumns);
        $removedColumn = $removedColumns[$from];
        $addedColumn = $addedColumns[$to];

        if ($from === 'deleted_at' || $to === 'deleted_at') {
            return [];
        }

        if ($this->normalizeSchemaType($removedColumn['type_name'] ?? null) !== $this->normalizeSchemaType($addedColumn->type)) {
            return [];
        }

        return [$from => $addedColumn->name];
    }

    private function isRenameTarget(string $columnName, array $renamePairs): bool
    {
        return in_array($columnName, array_values($renamePairs), true);
    }

    private function appendIndexDiffs(string $tableName, ColumnDefinition $column, bool $currentHasUnique, bool $currentHasIndex, array &$upLines, array &$downLines): void
    {
        if ($column->unique && ! $currentHasUnique) {
            $upLines[] = "\$table->unique('{$column->name}');";
            $downLines[] = "\$table->dropUnique(['{$column->name}']);";
        }

        if (! $column->unique && $currentHasUnique) {
            $upLines[] = "\$table->dropUnique(['{$column->name}']);";
            $downLines[] = "\$table->unique('{$column->name}');";
        }

        $desiredIndex = $column->index && ! $column->unique;
        $hasStandaloneIndex = $currentHasIndex && ! $currentHasUnique;

        if ($desiredIndex && ! $hasStandaloneIndex) {
            $upLines[] = "\$table->index('{$column->name}');";
            $downLines[] = "\$table->dropIndex(['{$column->name}']);";
        }

        if (! $desiredIndex && $hasStandaloneIndex) {
            $upLines[] = "\$table->dropIndex(['{$column->name}']);";
            $downLines[] = "\$table->index('{$column->name}');";
        }
    }

    private function needsColumnChange(ColumnDefinition $column, array $currentColumn): bool
    {
        $currentType = $this->normalizeSchemaType($currentColumn['type_name'] ?? null);
        $desiredType = $this->normalizeSchemaType($column->type);

        return $currentType !== $desiredType
            || (bool) ($currentColumn['nullable'] ?? false) !== $column->nullable
            || $this->normalizeDefaultValue($currentColumn['default'] ?? null) !== $column->default;
    }

    private function columnDefinitionFromSchema(array $currentColumn): ColumnDefinition
    {
        return new ColumnDefinition(
            name: (string) $currentColumn['name'],
            type: $this->normalizeSchemaType($currentColumn['type_name'] ?? null),
            nullable: (bool) ($currentColumn['nullable'] ?? false),
            unique: false,
            index: false,
            default: $this->normalizeDefaultValue($currentColumn['default'] ?? null),
        );
    }

    private function normalizeSchemaType(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'varchar', 'character varying' => 'string',
            'text', 'longtext' => 'text',
            'int', 'integer', 'mediumint', 'smallint', 'tinyint' => 'integer',
            'bigint', 'unsignedbigint' => 'unsignedBigInteger',
            'bool', 'boolean' => 'boolean',
            'json', 'jsonb' => 'json',
            'date' => 'date',
            'datetime', 'timestamp' => 'dateTime',
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            'blob' => 'text',
            default => strtolower((string) $type),
        };
    }

    private function normalizeDefaultValue(mixed $default): mixed
    {
        if ($default === null) {
            return null;
        }

        if (is_bool($default) || is_numeric($default)) {
            return $default;
        }

        $normalized = trim((string) $default);

        while (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $normalized = trim(substr($normalized, 1, -1));
        }

        if ((str_starts_with($normalized, "'") && str_ends_with($normalized, "'")) || (str_starts_with($normalized, '"') && str_ends_with($normalized, '"'))) {
            $normalized = substr($normalized, 1, -1);
        }

        if ($normalized === 'null') {
            return null;
        }

        if ($normalized === 'true') {
            return true;
        }

        if ($normalized === 'false') {
            return false;
        }

        if (is_numeric($normalized)) {
            return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
        }

        return $normalized;
    }
}
