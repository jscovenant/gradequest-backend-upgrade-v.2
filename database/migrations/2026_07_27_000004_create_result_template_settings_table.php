<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('result_template_settings')) {
            return;
        }

        Schema::create('result_template_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->unique();
            $table->string('template_key', 60)->default('classic_academic');
            $table->string('primary_color', 20)->default('#0f3d7a');
            $table->string('secondary_color', 20)->default('#c9a84c');
            $table->string('background_color', 20)->default('#ffffff');
            $table->string('font_family', 80)->default('Arial');
            $table->json('display_options')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_template_settings');
    }
};
