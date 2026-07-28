<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grade_settings')) {
            Schema::create('grade_settings', function (Blueprint $table) {
                $table->id();
                $table->string('min');
                $table->string('max');
                $table->string('grade');
                $table->string('remark');
                $table->timestamps();
            });
        }

        if (DB::table('grade_settings')->count() === 0) {
            DB::table('grade_settings')->insert([
                ['min' => '70', 'max' => '100', 'grade' => 'A', 'remark' => 'Excellent', 'created_at' => now(), 'updated_at' => now()],
                ['min' => '60', 'max' => '69.99', 'grade' => 'B', 'remark' => 'Very Good', 'created_at' => now(), 'updated_at' => now()],
                ['min' => '50', 'max' => '59.99', 'grade' => 'C', 'remark' => 'Good', 'created_at' => now(), 'updated_at' => now()],
                ['min' => '45', 'max' => '49.99', 'grade' => 'D', 'remark' => 'Fair', 'created_at' => now(), 'updated_at' => now()],
                ['min' => '40', 'max' => '44.99', 'grade' => 'E', 'remark' => 'Pass', 'created_at' => now(), 'updated_at' => now()],
                ['min' => '0', 'max' => '39.99', 'grade' => 'F', 'remark' => 'Fail', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_settings');
    }
};
