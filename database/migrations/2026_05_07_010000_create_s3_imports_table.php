<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('s3_imports', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('status')->default('pending');
            $table->unsignedInteger('orders_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s3_imports');
    }
};
