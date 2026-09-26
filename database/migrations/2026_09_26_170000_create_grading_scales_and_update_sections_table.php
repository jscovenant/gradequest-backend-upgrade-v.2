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
        // 1. Add grading toggles to sections table
        if (Schema::hasTable('sections')) {
            Schema::table('sections', function (Blueprint $table) {
                if (!Schema::hasColumn('sections', 'uses_grading_scale')) {
                    $table->boolean('uses_grading_scale')->default(true)->after('name');
                }
                if (!Schema::hasColumn('sections', 'grading_system_type')) {
                    $table->string('grading_system_type', 50)->nullable()->default('standard')->after('uses_grading_scale');
                }
            });
        }

        // 2. Create flexible section grading_scales table
        if (!Schema::hasTable('grading_scales')) {
            Schema::create('grading_scales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('section_id')->nullable()->index();
                $table->string('min', 20);
                $table->string('max', 20);
                $table->string('grade', 50);
                $table->string('remark', 100)->nullable();
                $table->decimal('gpa_point', 4, 2)->nullable();
                $table->string('color', 30)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('section_id')->references('id')->on('sections')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_scales');

        if (Schema::hasTable('sections')) {
            Schema::table('sections', function (Blueprint $table) {
                if (Schema::hasColumn('sections', 'uses_grading_scale')) {
                    $table->dropColumn('uses_grading_scale');
                }
                if (Schema::hasColumn('sections', 'grading_system_type')) {
                    $table->dropColumn('grading_system_type');
                }
            });
        }
    }
};
