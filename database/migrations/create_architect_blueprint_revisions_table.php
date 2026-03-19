<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('architect_blueprint_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('architect_blueprints')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['blueprint_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('architect_blueprint_revisions');
    }
};
