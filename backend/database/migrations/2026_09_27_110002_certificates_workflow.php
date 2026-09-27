<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Certificates (spec section 16): editable bilingual templates per type, and a draft → approved → revoked
 * workflow on certificates with a public verification token, grade, achievement text and a print counter.
 * Existing certificates were issued before approval existed, so they are backfilled as approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->unique();
            $table->string('title_ar', 150);
            $table->string('title_en', 150);
            $table->text('body_ar');
            $table->text('body_en');
            $table->string('signature1_name', 120)->nullable();
            $table->string('signature1_title', 120)->nullable();
            $table->string('signature2_name', 120)->nullable();
            $table->string('signature2_title', 120)->nullable();
            $table->string('ornament_level', 10)->default('full');
            $table->boolean('show_photo')->default(false);
            $table->timestamps();
            // Signature images live in media (collections signature_1 / signature_2).
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->string('achievement', 255)->nullable();
            $table->string('grade', 16)->nullable();
            $table->json('details')->nullable();
            $table->string('source', 20)->default('manual')->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('verify_token', 40)->nullable()->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->index(['source', 'source_id']);
        });

        foreach (DB::table('certificates')->whereNull('verify_token')->pluck('id') as $id) {
            DB::table('certificates')->where('id', $id)->update([
                'status' => 'approved',
                'verify_token' => Str::random(32),
                'approved_at' => now(),
                'source' => 'legacy',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex(['source', 'source_id']);
            $table->dropConstrainedForeignId('template_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropUnique(['verify_token']);
            $table->dropIndex(['status']);
            $table->dropIndex(['source']);
            $table->dropColumn(['status', 'achievement', 'grade', 'details', 'source', 'source_id', 'verify_token', 'approved_at', 'revoked_at', 'revoke_reason', 'print_count']);
        });
        Schema::dropIfExists('certificate_templates');
    }
};
