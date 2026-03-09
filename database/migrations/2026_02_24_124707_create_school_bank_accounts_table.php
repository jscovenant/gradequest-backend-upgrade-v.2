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
        Schema::create('school_bank_accounts', function (Blueprint $table) {
             
      $table->id();

      $table->unsignedBigInteger('school_id');

      // Bank details shown to parents
      $table->string('bank_name');          // e.g. GTBank
      $table->string('bank_code', 20)->nullable(); // optional (if you want)
      $table->string('account_name');       // e.g. Gradequest Int'l School
      $table->string('account_number', 20); // store as string

      // Optional extras
      $table->string('currency', 8)->default('NGN');
      $table->boolean('is_active')->default(true);
      $table->integer('sort_order')->default(0); // if multiple accounts
      $table->timestamps();

      // Allow multiple accounts per school (common in Nigeria)
      $table->index(['school_id', 'is_active']);

      $table->foreign('school_id')
        ->references('id')->on('school_settings')
        ->onDelete('cascade');
    });
      
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_bank_accounts');
    }
};
