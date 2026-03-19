<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_whatsapp_usages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('user_id');

            $table->date('cycle_start');
            $table->date('cycle_end');

            $table->unsignedInteger('allocated_credits')->default(0);
            $table->unsignedInteger('used_credits')->default(0);

            $table->timestamps();

            $table->unique(
                ['subscription_id', 'cycle_start', 'cycle_end'],
                'sub_whatsapp_usage_cycle_unique'
            );

            $table->index(['school_id', 'user_id']);
           $table->index( ['subscription_id', 'cycle_start', 'cycle_end'], 'sub_whatsapp_usage_cycle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_whatsapp_usages');
    }
};