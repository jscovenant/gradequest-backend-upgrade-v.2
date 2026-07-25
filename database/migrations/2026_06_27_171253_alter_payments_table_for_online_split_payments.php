<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'status')) {
                $table->enum('status', ['pending', 'success', 'failed'])
                    ->default('success')
                    ->after('payment_method');
            }

            if (! Schema::hasColumn('payments', 'platform_fee')) {
                $table->decimal('platform_fee', 10, 0)->default(0)->after('amount');
            }

            if (! Schema::hasColumn('payments', 'paystack_response')) {
                $table->json('paystack_response')->nullable()->after('reference');
            }

            if (! Schema::hasColumn('payments', 'paid_by')) {
                $table->unsignedBigInteger('paid_by')->nullable()->after('received_by');
            }
        });

        if (Schema::hasColumn('payments', 'received_by')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('received_by')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $columns = ['status', 'platform_fee', 'paystack_response', 'paid_by'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('payments', 'received_by')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('received_by')->nullable(false)->change();
            });
        }
    }
};
