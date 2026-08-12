<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->string('name');
            $table->string('start_location')->nullable();
            $table->string('end_location')->nullable();
            $table->decimal('default_fee', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('transport_route_id')->nullable()->constrained('transport_routes')->nullOnDelete();
            $table->string('registration_number');
            $table->string('name')->nullable();
            $table->unsignedInteger('capacity');
            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'registration_number']);
            $table->index(['school_id', 'is_active']);
        });

        Schema::create('transport_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained('transport_routes')->cascadeOnDelete();
            $table->string('name');
            $table->time('pickup_time')->nullable();
            $table->time('dropoff_time')->nullable();
            $table->decimal('fee', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['transport_route_id', 'name']);
        });

        Schema::create('transport_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('school_settings')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained('transport_routes')->cascadeOnDelete();
            $table->foreignId('transport_stop_id')->nullable()->constrained('transport_stops')->nullOnDelete();
            $table->foreignId('transport_vehicle_id')->constrained('transport_vehicles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('trip_type', ['pickup', 'dropoff', 'both'])->default('both');
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
            $table->index(['transport_vehicle_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        if (! Schema::hasTable('subscription_plans')) return;
        $featureRows = [
            ['feature_key' => 'transport_management', 'feature_name' => 'Transport Management'],
            ['feature_key' => 'support_transport_management', 'feature_name' => 'Transport Management'],
        ];
        $plans = DB::table('subscription_plans')->where(function ($query) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%legacy plus%'])->orWhereRaw('LOWER(name) LIKE ?', ['%gradequestplus%']);
        })->get();
        foreach ($plans as $plan) {
            $features = json_decode((string) ($plan->features ?? '[]'), true);
            $features = is_array($features) ? $features : [];
            foreach ($featureRows as $feature) {
                $features = array_values(array_filter($features, fn ($item) => strtolower((string) ($item['feature_key'] ?? '')) !== $feature['feature_key']));
                $features[] = $feature + ['is_enabled' => true, 'limit_type' => 'module', 'limit_count' => 0];
                if (Schema::hasTable('subscription_plan_features')) {
                    DB::table('subscription_plan_features')->updateOrInsert(
                        ['subscription_plan_id' => $plan->id, 'feature_key' => $feature['feature_key']],
                        $feature + ['is_enabled' => true, 'limit_type' => 'module', 'limit_count' => 0, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
            DB::table('subscription_plans')->where('id', $plan->id)->update(['features' => json_encode($features), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_assignments');
        Schema::dropIfExists('transport_stops');
        Schema::dropIfExists('transport_vehicles');
        Schema::dropIfExists('transport_routes');
    }
};
