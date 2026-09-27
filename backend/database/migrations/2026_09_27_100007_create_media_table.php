<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('model_type', 150);
            $table->unsignedBigInteger('model_id');
            $table->string('collection', 50)->index();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('original_name', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
