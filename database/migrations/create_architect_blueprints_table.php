<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('architect_blueprints', function (Blueprint $table) {
            $table->id();
            $table->string('model_name')->unique();
            $table->string('table_name');
            $table->string('primary_key_type')->default('id');
            $table->json('columns');
            $table->boolean('soft_deletes')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('architect_blueprints');
    }
};
