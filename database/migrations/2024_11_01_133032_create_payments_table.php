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
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('payments', 'school_id')) {
                    $table->unsignedBigInteger('school_id')->nullable()->index();
                }

                if (! Schema::hasColumn('payments', 'reference')) {
                    $table->string('reference')->nullable()->unique();
                }

                if (! Schema::hasColumn('payments', 'payment_id')) {
                    $table->string('payment_id')->nullable()->unique();
                }

                if (! Schema::hasColumn('payments', 'email')) {
                    $table->string('email')->nullable();
                }

                if (! Schema::hasColumn('payments', 'amount')) {
                    $table->decimal('amount', 10, 2)->default(0);
                }
            });

            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->string('reference')->unique();
            $table->string('payment_id')->nullable()->unique();
            $table->string('email')->nullable();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
