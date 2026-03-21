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

        DB::table('migrations')
            ->where('migration', 'like', "%_{$tableName}_table")
            ->delete();

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

        foreach (File::glob(database_path("migrations/*_{$tableName}_table.php")) as $migrationFile) {
            if (File::exists($migrationFile)) {
                File::delete($migrationFile);
            }
        }

        $blueprint->delete();
    }
}
