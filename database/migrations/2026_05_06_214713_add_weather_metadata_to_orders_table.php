<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {

            $table->float('weather_rain_mm')->nullable();
            $table->float('weather_temp_max')->nullable();
            $table->boolean('weather_blocked')->default(false);
            $table->timestamp('weather_checked_at')->nullable();

        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {

            $table->dropColumn([
                'weather_rain_mm',
                'weather_temp_max',
                'weather_blocked',
                'weather_checked_at'
            ]);

        });
    }
};
