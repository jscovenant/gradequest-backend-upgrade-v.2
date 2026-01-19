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
        Schema::create('show_grades', function (Blueprint $table) {
            $table->id();
              $table->bigInteger('school_id');
            
            $table->boolean('junior')->default(1);
            $table->boolean('senior')->default(1);
            $table->boolean('primary')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('show_grades');
    }
};
