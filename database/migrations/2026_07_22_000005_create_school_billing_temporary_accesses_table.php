<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_billing_temporary_accesses')) {
            Schema::create('school_billing_temporary_accesses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('scope')->default('school_crud');
                $table->string('status')->default('active');
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('ends_at');
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->string('reason')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'scope', 'status'], 'school_temp_access_status_idx');
                $table->index(['ends_at', 'status'], 'school_temp_access_expiry_idx');
                $table->foreign('school_id')->references('id')->on('school_settings')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_billing_temporary_accesses');
    }
};
