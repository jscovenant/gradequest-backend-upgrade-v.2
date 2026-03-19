<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('result_batches', function (Blueprint $table) {
            $table->date('submission_deadline')->nullable()->after('session')->index();
            $table->timestamp('computed_at')->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('computed_at');
            $table->timestamp('published_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('result_batches', function (Blueprint $table) {
            $table->dropColumn([
                'submission_deadline',
                'computed_at',
                'approved_at',
                'published_at',
            ]);
        });
    }
};