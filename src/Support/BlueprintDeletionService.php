<?php

namespace Lartisan\Architect\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lartisan\Architect\Models\Blueprint;

class BlueprintDeletionService
{
    public function deleteSnapshotOnly(Blueprint $blueprint): void
    {
        $blueprint->delete();
    }

    public function deleteBlueprintAndArtifacts(Blueprint $blueprint): void
    {
        $modelName = $blueprint->model_name;
        $tableName = $blueprint->table_name;

        Schema::dropIfExists($tableName);

        $this->purgeMigrationRecords($tableName);

        $filesToDelete = [
            GenerationPathResolver::model($modelName),
            GenerationPathResolver::factory("{$modelName}Factory"),
            GenerationPathResolver::seeder("{$modelName}Seeder"),
            GenerationPathResolver::resource("{$modelName}Resource"),
        ];

        foreach ($filesToDelete as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        $resourceDirectory = GenerationPathResolver::resourceDirectory("{$modelName}Resource");

        if (File::isDirectory($resourceDirectory)) {
            File::deleteDirectory($resourceDirectory);
        }

        foreach ($this->findAllMigrationFiles($tableName) as $migrationFile) {
            File::delete($migrationFile);
        }

        app(BlueprintGenerationHookRegistry::class)->runAfterDelete($blueprint);

        $blueprint->delete();
    }

    /**
     * Returns all migration file paths related to the given table, covering:
     * - create_{table}_table and update_{table}_table
     * - add_column(s)_*_to_{table}
     * - update_column(s)_*_on_{table}
     *
     * @return array<int, string>
     */
    private function findAllMigrationFiles(string $tableName): array
    {
        $globs = [
            database_path("migrations/*_{$tableName}_table.php"),
            database_path("migrations/*_to_{$tableName}.php"),
            database_path("migrations/*_on_{$tableName}.php"),
        ];

        return array_values(array_merge(...array_map(
            fn (string $pattern) => File::glob($pattern),
            $globs,
        )));
    }

    private function purgeMigrationRecords(string $tableName): void
    {
        DB::table('migrations')
            ->where(function ($query) use ($tableName): void {
                $query->where('migration', 'like', "%_{$tableName}_table")
                    ->orWhere('migration', 'like', "%_to_{$tableName}")
                    ->orWhere('migration', 'like', "%_on_{$tableName}");
            })
            ->delete();
    }
}
