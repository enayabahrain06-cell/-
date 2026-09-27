<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('students')->cascadeOnDelete();
            $table->bigInteger('balance_fils')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('amount_fils');
            $table->unsignedInteger('paid_fils')->default(0);
            $table->date('due_date')->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('term', 60)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reminder_before_sent_at')->nullable();
            $table->dateTime('reminder_after_sent_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedInteger('amount_fils'); // always positive; refunds live in refunds
            $table->string('method', 32)->index();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->index();
            $table->dateTime('receipt_sent_at')->nullable();
            $table->timestamps();
            // receipt image + receipt PDF live in media (collections receipt_image, receipt_pdf)
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedInteger('amount_fils');
            $table->timestamps();

            $table->unique(['invoice_id', 'payment_id']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->bigInteger('amount_fils');        // signed delta
            $table->bigInteger('balance_after_fils');
            $table->string('reference', 120)->nullable()->index();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->text('note')->nullable();          // required for adjustments (validated)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_no', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->unsignedInteger('amount_fils');
            $table->string('method', 32)->index();
            $table->string('reference', 120)->nullable();
            $table->text('note');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('wallets');
    }
};
