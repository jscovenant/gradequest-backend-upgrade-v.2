<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hostel_allocations', function (Blueprint $table) {
            $table->foreignId('session_id')->nullable()->after('student_id')->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('term_id')->nullable()->after('session_id')->constrained('terms')->nullOnDelete();
            $table->index(['school_id', 'session_id', 'term_id', 'status'], 'hostel_allocation_period_status');
        });
        Schema::create('hostel_allocation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('hostel_allocation_id')->constrained('hostel_allocations')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_hostel_id')->nullable()->constrained('hostels')->nullOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('hostel_rooms')->nullOnDelete();
            $table->foreignId('to_hostel_id')->nullable()->constrained('hostels')->nullOnDelete();
            $table->foreignId('to_room_id')->nullable()->constrained('hostel_rooms')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_allocation_events');
        Schema::table('hostel_allocations', function (Blueprint $table) {
            $table->dropIndex('hostel_allocation_period_status');
            $table->dropConstrainedForeignId('term_id');
            $table->dropConstrainedForeignId('session_id');
        });
    }
};
