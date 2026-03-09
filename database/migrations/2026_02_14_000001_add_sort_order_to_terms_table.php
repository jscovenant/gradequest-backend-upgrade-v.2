<?php

// database/migrations/2026_02_14_000001_add_sort_order_to_terms_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('terms', function (Blueprint $table) {
      $table->unsignedInteger('sort_order')->default(0)->after('school_id');
    });
  }

  public function down(): void
  {
    Schema::table('terms', function (Blueprint $table) {
      $table->dropColumn('sort_order');
    });
  }
};
