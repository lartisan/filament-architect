<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `snapshot_version` and `meta` to the blueprint revisions table.
 *
 * This migration is a no-op for fresh installs (both columns are already
 * included in the create migration). It only applies when upgrading from
 * v0.2.x, where the revisions table existed without these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('architect_blueprint_revisions', function (Blueprint $table) {
            if (! Schema::hasColumn('architect_blueprint_revisions', 'snapshot_version')) {
                $table->unsignedSmallInteger('snapshot_version')->default(1)->after('revision');
            }

            if (! Schema::hasColumn('architect_blueprint_revisions', 'meta')) {
                $table->json('meta')->nullable()->after('snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('architect_blueprint_revisions', function (Blueprint $table) {
            if (Schema::hasColumn('architect_blueprint_revisions', 'snapshot_version')) {
                $table->dropColumn('snapshot_version');
            }

            if (Schema::hasColumn('architect_blueprint_revisions', 'meta')) {
                $table->dropColumn('meta');
            }
        });
    }
};
