<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home address, read from the ID card (one line: flat, building, road, block, area) or typed by staff.
 * On a request it travels to the student when the request is accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('guardian_phone');
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('guardian_phone');
        });
    }

    public function down(): void
    {
        Schema::table('registration_requests', fn (Blueprint $table) => $table->dropColumn('address'));
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('address'));
    }
};
