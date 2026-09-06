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
        if (! Schema::hasTable('school_staff_ai_credit_allocations')) {
            Schema::create('school_staff_ai_credit_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedInteger('allocated_credits')->default(0);
                $table->unsignedInteger('used_credits')->default(0);
                $table->boolean('is_unlimited')->default(false);
                $table->unsignedBigInteger('allocated_by')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'user_id'], 'school_staff_ai_alloc_unique');
            });
        }

        if (Schema::hasTable('ai_credit_transactions') && ! Schema::hasColumn('ai_credit_transactions', 'user_id')) {
            Schema::table('ai_credit_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->index()->after('subscription_ai_usage_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_credit_transactions') && Schema::hasColumn('ai_credit_transactions', 'user_id')) {
            Schema::table('ai_credit_transactions', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }

        Schema::dropIfExists('school_staff_ai_credit_allocations');
    }
};
