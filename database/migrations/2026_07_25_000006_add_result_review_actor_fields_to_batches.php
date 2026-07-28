<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('result_batches')) {
            return;
        }

        Schema::table('result_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('result_batches', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at')->index();
            }
            if (! Schema::hasColumn('result_batches', 'published_by')) {
                $table->unsignedBigInteger('published_by')->nullable()->after('published_at')->index();
            }
            if (! Schema::hasColumn('result_batches', 'review_note')) {
                $table->text('review_note')->nullable()->after('published_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('result_batches')) {
            return;
        }

        Schema::table('result_batches', function (Blueprint $table) {
            foreach (['review_note', 'published_by', 'approved_by'] as $column) {
                if (Schema::hasColumn('result_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
