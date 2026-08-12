<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('terms_version', 40);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'terms_version']);
            $table->index(['school_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_terms_acceptances');
    }
};
