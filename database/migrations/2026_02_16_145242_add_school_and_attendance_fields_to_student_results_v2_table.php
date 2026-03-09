<?php




use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('student_results_v2', function (Blueprint $table) {
            // varchar(255) nullable, keep DB charset default (if you need explicit collation uncomment ->collation(...))
            $table->string('school_open', 255)->nullable()->after('meta_json');
            $table->string('school_close', 255)->nullable()->after('school_open');
            $table->string('no_present', 255)->nullable()->after('school_close');
            $table->string('no_absent', 255)->nullable()->after('no_present');

            // resumption_date stored as DATE; use nullable() so missing values are allowed
            $table->date('resumption_date')->nullable()->after('no_absent');
        });
    }

    public function down()
    {
        Schema::table('student_results_v2', function (Blueprint $table) {
            $table->dropColumn([
                'school_open',
                'school_close',
                'no_present',
                'no_absent',
                'resumption_date',
            ]);
        });
    }
};
