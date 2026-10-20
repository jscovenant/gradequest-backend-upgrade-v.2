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
        if (!Schema::hasTable('principal_sections')) {
            Schema::create('principal_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'section_id'], 'principal_section_unique');
                $table->index(['school_id', 'user_id']);
                $table->index(['school_id', 'section_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('principal_sections');
    }
};
