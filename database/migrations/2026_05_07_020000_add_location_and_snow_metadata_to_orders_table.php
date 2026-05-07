<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('latitude', 10, 6)->nullable()->after('city');
            $table->decimal('longitude', 10, 6)->nullable()->after('latitude');
            $table->string('destination_timezone')->nullable()->after('longitude');
            $table->float('weather_snow_cm')->nullable()->after('weather_rain_mm');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'latitude',
                'longitude',
                'destination_timezone',
                'weather_snow_cm',
            ]);
        });
    }
};
