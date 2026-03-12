<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lartisan\Architect\Enums\GenerationMode;
use Lartisan\Architect\ValueObjects\BlueprintData;
use Lartisan\Architect\ValueObjects\PlannedArtifact;
use Lartisan\Architect\ValueObjects\PlannedSchemaOperation;
use Lartisan\Architect\ValueObjects\RegenerationPlan;

class RegenerationPlanner
{
    private const array RESERVED_COLUMNS = ['id', 'created_at', 'updated_at'];

    public function plan(BlueprintData $blueprint): RegenerationPlan
    {
        return new RegenerationPlan(
            artifacts: $this->planArtifacts($blueprint),
            schemaOperations: $this->planSchemaOperations($blueprint),
        );
    }

    /**
     * @return array<PlannedArtifact>
     */
    private function planArtifacts(BlueprintData $blueprint): array
    {
        $artifacts = [
            $this->planMigrationArtifact($blueprint),
            $this->planSimpleArtifact('Model', GenerationPathResolver::model($blueprint->modelName), $blueprint->generationMode),
        ];

        if ($blueprint->generateFactory) {
            $artifacts[] = $this->planSimpleArtifact('Factory', GenerationPathResolver::factory("{$blueprint->modelName}Factory"), $blueprint->generationMode);
        }

        if ($blueprint->generateSeeder) {
            $artifacts[] = $this->planSimpleArtifact('Seeder', GenerationPathResolver::seeder("{$blueprint->modelName}Seeder"), $blueprint->generationMode);
        }

        if ($blueprint->generateResource) {
            $resourcePath = GenerationPathResolver::resource("{$blueprint->modelName}Resource");
            $artifacts[] = $this->planSimpleArtifact('Filament Resource', $resourcePath, $blueprint->generationMode);

            foreach ($this->resourcePages($blueprint->modelName) as $label => $path) {
                $action = File::exists($path) && $blueprint->generationMode !== GenerationMode::Replace ? 'preserve' : 'create';
                $artifacts[] = new PlannedArtifact(
                    label: $label,
                    path: $path,
                    action: $action,
                    reason: $action === 'preserve'
                        ? 'Existing page class will stay untouched in merge/create mode.'
                        : 'Missing page class will be generated for the resource scaffold.',
                );
            }
        }

        return $artifacts;
    }

    private function planMigrationArtifact(BlueprintData $blueprint): PlannedArtifact
    {
        $existingCreateMigration = $this->findCreateMigrationPath($blueprint->tableName);

        if ($existingCreateMigration !== null && ! $this->hasMigrationRun($existingCreateMigration)) {
            return new PlannedArtifact(
                'Migration',
                $existingCreateMigration,
                $blueprint->generationMode === GenerationMode::Create ? 'preserve' : 'merge',
                'pending create migration',
                $blueprint->generationMode === GenerationMode::Create
                    ? 'A pending create migration already exists, so Architect will leave it as-is.'
                    : 'A pending create migration already exists, so Architect will update that file instead of creating a new one.',
            );
        }

        if (Schema::hasTable($blueprint->tableName)) {
            return new PlannedArtifact(
                'Migration',
                database_path('migrations'),
                $blueprint->generationMode === GenerationMode::Create ? 'skip' : 'sync',
                $blueprint->generationMode === GenerationMode::Create ? 'table already exists' : 'sync migration',
                $blueprint->generationMode === GenerationMode::Create
                    ? 'Create mode will not generate another migration because the table already exists.'
                    : 'The table already exists, so Architect will generate a sync migration for the detected differences.',
            );
        }

        return new PlannedArtifact('Migration', database_path('migrations'), 'create', 'new create migration', 'Architect will generate a fresh create-table migration.');
    }

    private function planSimpleArtifact(string $label, string $path, GenerationMode $mode): PlannedArtifact
    {
        if (! File::exists($path)) {
            return new PlannedArtifact($label, $path, 'create', reason: 'The file does not exist yet, so Architect will generate it.');
        }

        $action = match ($mode) {
            GenerationMode::Create => 'preserve',
            GenerationMode::Merge => 'merge',
            GenerationMode::Replace => 'replace',
        };

        return new PlannedArtifact(
            $label,
            $path,
            $action,
            reason: match ($action) {
                'preserve' => 'Create mode keeps the existing file unchanged.',
                'merge' => 'Managed generated sections will be refreshed while custom code stays in place where possible.',
                'replace' => 'Replace mode will rewrite the generated artifact from the latest blueprint.',
            },
        );
    }

    /**
     * @return array<PlannedSchemaOperation>
     */
    private function planSchemaOperations(BlueprintData $blueprint): array
    {
        if (! Schema::hasTable($blueprint->tableName)) {
            return [new PlannedSchemaOperation('create', "Create table {$blueprint->tableName}", reason: 'The table does not exist yet, so a full create migration is needed.')];
        }

        $operations = [];
        $currentColumns = collect(Schema::getColumns($blueprint->tableName))->keyBy(fn (array $column) => strtolower($column['name']));
        $desiredColumns = collect($blueprint->columns)->keyBy(fn ($column) => strtolower($column->name));
        $removedColumns = $this->findRemovedColumns($currentColumns, $desiredColumns, $blueprint->softDeletes);
        $addedColumns = $this->findAddedColumns($blueprint, $currentColumns);
        $likelyRenames = $this->detectLikelyRenames($removedColumns, $addedColumns);
        $tableHasRows = $this->tableHasRows($blueprint->tableName);

        foreach ($blueprint->columns as $column) {
            $current = $currentColumns->get(strtolower($column->name));

            if (! is_array($current)) {
                if ($this->isRenameTarget($column->name, $likelyRenames)) {
                    continue;
                }

                if ($tableHasRows && ! $column->nullable && ($column->default === null || $column->default === '')) {
                    $operations[] = new PlannedSchemaOperation(
                        action: 'add',
                        description: "Add column {$column->name}",
                        risky: true,
                        deferred: true,
                        reason: 'This new column is required and has no default value, but the table already contains rows. SQLite cannot add a NOT NULL column in this situation, and other databases still require a backfill strategy.',
                    );

                    continue;
                }

                $operations[] = new PlannedSchemaOperation('add', "Add column {$column->name}", reason: 'This column is present in the blueprint but not in the current table schema.');

                continue;
            }

            $changes = [];
            $currentType = $this->normalizeSchemaType($current['type_name'] ?? null);
            $desiredType = $this->normalizeSchemaType($column->type);

            if ($currentType !== $desiredType) {
                $changes[] = "type {$currentType} → {$desiredType}";
            }

            if ((bool) ($current['nullable'] ?? false) !== $column->nullable) {
                $changes[] = $column->nullable ? 'make nullable' : 'make required';
            }

            if ($this->normalizeDefaultValue($current['default'] ?? null) !== $column->default) {
                $changes[] = 'change default';
            }

            $hasUnique = Schema::hasIndex($blueprint->tableName, [$column->name], 'unique');
            $hasIndex = Schema::hasIndex($blueprint->tableName, [$column->name], 'index');

            if ($column->unique !== $hasUnique) {
                $changes[] = $column->unique ? 'add unique index' : 'drop unique index';
            }

            $desiredIndex = $column->index && ! $column->unique;
            $hasStandaloneIndex = $hasIndex && ! $hasUnique;

            if ($desiredIndex !== $hasStandaloneIndex) {
                $changes[] = $desiredIndex ? 'add index' : 'drop index';
            }

            if ($changes !== []) {
                $operations[] = new PlannedSchemaOperation('change', "Update {$column->name}: ".implode(', ', $changes), reason: 'The existing database column differs from the current blueprint definition.');
            }
        }

        foreach ($likelyRenames as $from => $to) {
            $operations[] = new PlannedSchemaOperation(
                action: 'rename',
                description: "Rename column {$from} → {$to}",
                risky: true,
                deferred: ! $blueprint->allowLikelyRenames,
                reason: 'Architect detected a likely rename based on one removed column and one added column with the same normalized type.',
            );
        }

        foreach ($removedColumns as $name => $column) {
            if (isset($likelyRenames[$name])) {
                continue;
            }

            if ($name === 'deleted_at') {
                $operations[] = new PlannedSchemaOperation(
                    action: 'remove',
                    description: 'Remove soft deletes column',
                    risky: true,
                    deferred: ! $blueprint->allowDestructiveChanges,
                    reason: 'The blueprint no longer enables soft deletes, so dropping deleted_at would be destructive.',
                );

                continue;
            }

            $operations[] = new PlannedSchemaOperation(
                action: 'remove',
                description: "Remove column {$name}",
                risky: true,
                deferred: ! $blueprint->allowDestructiveChanges,
                reason: 'The column no longer exists in the blueprint, so removing it would delete data.',
            );
        }

        if ($blueprint->softDeletes && ! $currentColumns->has('deleted_at')) {
            $operations[] = new PlannedSchemaOperation('add', 'Add soft deletes column', reason: 'Soft deletes are enabled in the blueprint but deleted_at is missing from the table.');
        }

        if ($operations === []) {
            $operations[] = new PlannedSchemaOperation('noop', 'No schema changes detected', reason: 'The current database schema already matches the blueprint.');
        }

        return $operations;
    }

    /**
     * @return array<string, string>
     */
    private function resourcePages(string $modelName): array
    {
        $resourceDirectory = GenerationPathResolver::resourceDirectory("{$modelName}Resource");
        $plural = Str::plural($modelName);

        return [
            'Resource Page: List' => "{$resourceDirectory}/Pages/List{$plural}.php",
            'Resource Page: Create' => "{$resourceDirectory}/Pages/Create{$modelName}.php",
            'Resource Page: Edit' => "{$resourceDirectory}/Pages/Edit{$modelName}.php",
            'Resource Page: View' => "{$resourceDirectory}/Pages/View{$modelName}.php",
        ];
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

    private function findRemovedColumns($currentColumns, $desiredColumns, bool $softDeletes): array
    {
        $removed = [];

        foreach ($currentColumns as $name => $column) {
            if (in_array($name, self::RESERVED_COLUMNS, true)) {
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

    private function findAddedColumns(BlueprintData $blueprint, $currentColumns): array
    {
        return collect($blueprint->columns)
            ->filter(fn ($column) => ! $currentColumns->has(strtolower($column->name)))
            ->keyBy(fn ($column) => strtolower($column->name))
            ->all();
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

    private function isRenameTarget(string $columnName, array $likelyRenames): bool
    {
        return in_array($columnName, array_values($likelyRenames), true);
    }

    private function tableHasRows(string $tableName): bool
    {
        return DB::table($tableName)->exists();
    }
}
