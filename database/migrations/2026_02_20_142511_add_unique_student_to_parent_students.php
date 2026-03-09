<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_unique_student_to_parent_students.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('parent_students', function (Blueprint $table) {
      // prevent a student being linked to multiple parents
      $table->unique('student_id', 'parent_students_student_id_unique');
    });
  }

  public function down(): void
  {
    Schema::table('parent_students', function (Blueprint $table) {
      $table->dropUnique('parent_students_student_id_unique');
    });
  }
};