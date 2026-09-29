<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bahrain personal number (CPR), read from the ID card or typed by staff. Unique per student so the same
 * child cannot be enrolled twice; on a request it travels to the student when the request is accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('cpr', 9)->nullable()->unique()->after('student_no');
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->string('cpr', 9)->nullable()->index()->after('request_no');
        });
    }

    public function down(): void
    {
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropIndex(['cpr']);
            $table->dropColumn('cpr');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['cpr']);
            $table->dropColumn('cpr');
        });
    }
};
