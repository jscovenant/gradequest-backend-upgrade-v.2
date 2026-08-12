<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_transcript_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('downloaded_by')->index();
            $table->unsignedInteger('record_count')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'created_at'], 'transcript_export_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transcript_exports');
    }
};
