<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('architect_blueprint_revisions', 'snapshot_version')) {
            Schema::table('architect_blueprint_revisions', function (Blueprint $table) {
                $table->unsignedSmallInteger('snapshot_version')->default(1)->after('revision');
            });
        }

        if (! Schema::hasColumn('architect_blueprint_revisions', 'meta')) {
            Schema::table('architect_blueprint_revisions', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('snapshot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('architect_blueprint_revisions', 'meta')) {
            Schema::table('architect_blueprint_revisions', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }

        if (Schema::hasColumn('architect_blueprint_revisions', 'snapshot_version')) {
            Schema::table('architect_blueprint_revisions', function (Blueprint $table) {
                $table->dropColumn('snapshot_version');
            });
        }
    }
};
