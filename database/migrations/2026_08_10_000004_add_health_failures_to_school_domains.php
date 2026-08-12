<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_domains', function (Blueprint $table) {
            $table->unsignedSmallInteger('consecutive_health_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('school_domains', function (Blueprint $table) {
            $table->dropColumn('consecutive_health_failures');
        });
    }
};
