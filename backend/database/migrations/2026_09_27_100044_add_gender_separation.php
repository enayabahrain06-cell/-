<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gender separation: two fully separated tracks (male / female).
 *
 * - users.track: which track a staff member works in (male / female / both). Super Admin is always both (in code).
 * - locations.gender: male / female / shared (shared halls host either gender, never both at once).
 * - lessons, location_bookings, exams, lotteries get an explicit gender (male / female only).
 *   Existing rows are backfilled from their package / lesson through the query builder (portable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('track', 32)->default('both')->index();
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->string('gender', 32)->default('shared')->index();
        });
        foreach (['lessons', 'location_bookings', 'exams', 'lotteries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('gender', 32)->nullable()->index();
            });
        }

        // Backfill (no data in fresh installs; kept for databases created before this migration).
        DB::table('packages')->where('gender', 'mixed')->update(['gender' => 'male']);
        foreach (DB::table('packages')->get(['id', 'gender']) as $p) {
            DB::table('lessons')->where('package_id', $p->id)->update(['gender' => $p->gender]);
            DB::table('lotteries')->where('package_id', $p->id)->update(['gender' => $p->gender]);
            DB::table('exams')->where('package_id', $p->id)->whereNull('gender')->update(['gender' => $p->gender]);
        }
        foreach (DB::table('lessons')->whereNotNull('gender')->get(['id', 'gender']) as $l) {
            DB::table('exams')->where('lesson_id', $l->id)->whereNull('gender')->update(['gender' => $l->gender]);
        }
    }

    public function down(): void
    {
        foreach (['lotteries', 'exams', 'location_bookings', 'lessons', 'locations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['gender']);
                $table->dropColumn('gender');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['track']);
            $table->dropColumn('track');
        });
    }
};
