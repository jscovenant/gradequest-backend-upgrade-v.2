<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->string('name');
            $table->enum('gender', ['male', 'female', 'mixed'])->default('mixed');
            $table->string('warden_name')->nullable();
            $table->string('warden_phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('hostel_id')->constrained('hostels')->cascadeOnDelete();
            $table->string('name');
            $table->string('floor')->nullable();
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['hostel_id', 'name']);
            $table->index(['school_id', 'is_active']);
        });

        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('hostel_id')->constrained('hostels')->cascadeOnDelete();
            $table->foreignId('hostel_room_id')->constrained('hostel_rooms')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'checked_out'])->default('active');
            $table->timestamp('allocated_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
            $table->index(['hostel_room_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        $features = [
            ['feature_key' => 'hostel_management', 'feature_name' => 'Hostel Management'],
            ['feature_key' => 'support_hostel_management', 'feature_name' => 'Hostel Management'],
        ];

        if (! Schema::hasTable('subscription_plans')) return;

        $plans = DB::table('subscription_plans')->where(function ($query) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%legacy plus%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%gradequestplus%']);
        })->get();

        foreach ($plans as $plan) {
            $configured = json_decode((string) ($plan->features ?? '[]'), true);
            $configured = is_array($configured) ? $configured : [];
            foreach ($features as $feature) {
                $configured = array_values(array_filter($configured, fn ($item) => strtolower((string) ($item['feature_key'] ?? '')) !== $feature['feature_key']));
                $configured[] = $feature + ['is_enabled' => true, 'limit_type' => 'module', 'limit_count' => 0];
                if (Schema::hasTable('subscription_plan_features')) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        ['subscription_plan_id' => $plan->id, 'feature_key' => $feature['feature_key']],
                        $feature + ['is_enabled' => true, 'limit_type' => 'module', 'limit_count' => 0, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
            DB::table('subscription_plans')->where('id', $plan->id)->update(['features' => json_encode($configured), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_allocations');
        Schema::dropIfExists('hostel_rooms');
        Schema::dropIfExists('hostels');
    }
};
