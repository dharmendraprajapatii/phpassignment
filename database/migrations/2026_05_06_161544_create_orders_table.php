<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->nullable();
            $table->string('order_id');
            $table->timestamp('placed_at');
            $table->text('address');
            $table->string('country');
            $table->decimal('value', 10, 2);
            $table->string('currency');
            $table->integer('weight');
            $table->string('payment_mode');
            $table->string('ingestion_source')->default('api');
            $table->string('source_reference')->nullable();
            $table->string('city')->nullable();
            $table->date('dispatch_date')->nullable();
            $table->decimal('converted_value', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
