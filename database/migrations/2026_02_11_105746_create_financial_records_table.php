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
        Schema::create('financial_records', function (Blueprint $table) {
             $table->id();
    $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
    $table->foreignId('category_id')->constrained('financial_categories')->cascadeOnDelete();

    $table->date('date');
    $table->string('title');
    $table->enum('type', ['income', 'expense']);
    $table->decimal('amount', 15, 2);
    $table->enum('status', ['paid', 'pending'])->default('paid');

    $table->timestamps();

    $table->index(['school_id', 'date']);
    $table->index(['school_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_records');
    }
};
