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
        Schema::create('dispatch_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->string('city');
            $table->string('country');
            $table->date('dispatch_date');
            $table->decimal('total_value', 12, 2)->default(0);
            $table->string('currency');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatch_runs');
    }
};
