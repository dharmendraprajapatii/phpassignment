<?php

namespace App\Services;

use App\Models\Order;
use App\Models\DispatchBatch;
use App\Models\DispatchRun;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DispatchService
{
    private $holidayCache = [];

    public function __construct(
        private GeocodingService $geocodingService
    ) {
    }

    public function process($batchId)
    {
        $orders = Order::where('batch_id', $batchId)->get();

        if ($orders->isEmpty()) {
            \Log::warning("No orders found", ['batch_id' => $batchId]);
            return [];
        }

        $failedOrders = [];

        foreach ($orders as $order) {

            try {

                $location = $this->geocodingService->resolve($order->address, $order->country);
                $order->city = $location['city'] ?? 'Unknown';
                $order->latitude = $location['latitude'] ?? null;
                $order->longitude = $location['longitude'] ?? null;
                $order->destination_timezone = $location['timezone'] ?? $this->defaultTimezone($order->country);

                // ✅ INITIAL DATE
                $date = $this->nextWorkingDay(
                    $order->placed_at,
                    $order->country,
                    $order->destination_timezone
                );
                $attempts = 0;
                $maxAttempts = 5;
                $weather = null;
                $wasBlocked = false;

                while ($attempts < $maxAttempts) {
                    $weather = $this->getWeatherData($location, $date);

                    if (!$weather) {
                        $weather = [
                            'rain' => 999,
                            'snow' => 999,
                            'temp' => 50,
                            'blocked' => true
                        ];
                    }

                    if ($weather['blocked']) {
                        $wasBlocked = true; // 🔥 track this
                    }

                    if (!$weather['blocked']) {
                        break;
                    }

                    $date = $this->nextWorkingDay($date, $order->country, $order->destination_timezone);
                    $attempts++;
                }

                if (!$weather) {
                    $weather = [
                        'rain' => 0,
                        'snow' => 0,
                        'temp' => 25,
                        'blocked' => false
                    ];
                }

                if (empty($date)) {
                    $date = $this->parseDateForTimezone($order->placed_at, $order->destination_timezone)
                        ->addDay()
                        ->toDateString();
                }

                $order->is_deferred = $wasBlocked;
                $order->dispatch_date = $date;

                if ($order->payment_mode === 'cod') {
                    $order->converted_value = $this->convertCurrency($order);
                } else {
                    $order->converted_value = $order->value;
                }

                $order->weather_meta = json_encode($weather);
                $order->weather_rain_mm = $weather['rain'];
                $order->weather_snow_cm = $weather['snow'];
                $order->weather_temp_max = $weather['temp'];
                $order->weather_blocked = $weather['blocked'];
                $order->weather_checked_at = now();

                $order->save();

            } catch (\Exception $e) {

                $failedOrders[] = $order->order_id;

                \Log::error("Order processing failed", [
                    'order_id' => $order->order_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $updatedOrders = Order::where('batch_id', $batchId)->get();

        // ✅ CLEAN OLD RUNS
        DispatchRun::where('batch_id', $batchId)->delete();

        // ✅ GROUP
        $this->groupRuns($updatedOrders, $batchId);

        // ✅ EXPORT
        $this->exportCsv($batchId, $updatedOrders);

        // ✅ MARK PROCESSED
        DispatchBatch::where('id', $batchId)
            ->update([
                'processed_at' => now(),
                'failed_orders' => json_encode($failedOrders),
                'status' => empty($failedOrders) ? 'processed' : 'processed_with_failures',
            ]);

        return [
            'orders' => $updatedOrders,
            'failed_orders' => $failedOrders
        ];
    }

    private function nextWorkingDay($date, $country, $timezone)
    {
        $d = $this->parseDateForTimezone($date, $timezone);

        do {
            $d->addDay();
        } while (
            $d->isWeekend() ||
            $this->isHoliday($d->toDateString(), $country)
        );

        return $d->toDateString();
    }

    private function parseDateForTimezone($date, string $timezone): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            return Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
        }

        return Carbon::parse($date)->setTimezone($timezone);
    }

    private function getWeatherData(array $location, $date)
    {
        $latitude = $location['latitude'] ?? 0;
        $longitude = $location['longitude'] ?? 0;
        $timezone = $location['timezone'] ?? 'UTC';

        try {
            $res = Http::timeout(5)->retry(2, 200)->get(
                config('services.open_meteo.forecast_url'),
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'daily' => 'precipitation_sum,snowfall_sum,temperature_2m_max',
                    'timezone' => $timezone,
                ]
            );

            if (!$res->ok()) return null;

            $data = $res->json();
            $dates = $data['daily']['time'] ?? [];

            $index = array_search($date, $dates);

            // ❗ CRITICAL FIX: Missing forecast = BLOCKED
            if ($index === false) {
                return [
                    'rain' => 999,
                    'snow' => 999,
                    'temp' => 50,
                    'blocked' => true
                ];
            }

            $rain = $data['daily']['precipitation_sum'][$index] ?? 0;
            $snow = $data['daily']['snowfall_sum'][$index] ?? 0;
            $temp = $data['daily']['temperature_2m_max'][$index] ?? 25;

            return [
                'rain' => $rain,
                'snow' => $snow,
                'temp' => $temp,
                'blocked' => ($rain > 20 || $snow > 0 || $temp > 45 || $temp < -10)
            ];

        } catch (\Exception $e) {
            \Log::error("Weather API failed", ['error' => $e->getMessage()]);

            // ❗ Treat failure as BLOCKED
            return [
                'rain' => 999,
                'snow' => 999,
                'temp' => 50,
                'blocked' => true
            ];
        }
    }

    private function convertCurrency($order)
    {
        $to = $order->country === 'IN' ? 'INR' : 'EUR';

        try {
            $res = Http::timeout(5)->retry(2, 200)->get(
                "https://api.frankfurter.app/latest",
                [
                    'amount' => $order->value,
                    'from' => $order->currency,
                    'to' => $to
                ]
            );

            if (!$res->ok()) {
                return $order->value;
            }

            return $res['rates'][$to] ?? $order->value;

        } catch (\Exception $e) {
            return $order->value;
        }
    }

    private function groupRuns($orders, $batchId)
    {
        $validOrders = $orders->filter(fn($o) =>
            !empty($o->city) && !empty($o->dispatch_date)
        );

        $groups = $validOrders->groupBy(fn($o) =>
            $o->city . '_' . $o->dispatch_date
        );

        foreach ($groups as $group) {

            $first = $group->first();

            DispatchRun::create([
                'batch_id' => $batchId,
                'city' => $first->city ?? 'Unknown',
                'country' => $first->country,
                'dispatch_date' => $first->dispatch_date,
                'total_value' => $group->sum(fn($o) =>
                    $o->converted_value ?? $o->value
                ),
                'currency' => $first->country === 'IN' ? 'INR' : 'EUR',
                'weather_summary' => json_encode([
                    'blocked_orders' => $group->where('is_deferred', true)->count(),
                    'max_rain_mm' => $group->max('weather_rain_mm'),
                    'max_snow_cm' => $group->max('weather_snow_cm'),
                    'max_temp_c' => $group->max('weather_temp_max'),
                ]),
            ]);
        }
    }

    private function exportCsv($batchId, $orders)
    {
        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [
            'batch_id',
            'order_id',
            'country',
            'city',
            'destination_timezone',
            'dispatch_date',
            'payment_mode',
            'is_deferred',
            'invoiced_value_local',
            'invoiced_currency',
            'weather_blocked',
            'weather_rain_mm',
            'weather_snow_cm',
            'weather_temp_max',
        ]);

        foreach ($orders as $o) {
            $currency = $o->payment_mode === 'cod'
                ? ($o->country === 'IN' ? 'INR' : 'EUR')
                : $o->currency;

            $value = $o->converted_value ?? $o->value;

            fputcsv($stream, [
                $batchId,
                $o->order_id,
                $o->country,
                $o->city,
                $o->destination_timezone,
                $o->dispatch_date,
                $o->payment_mode,
                $o->is_deferred ? 'true' : 'false',
                $value,
                $currency,
                $o->weather_blocked ? 'true' : 'false',
                $o->weather_rain_mm,
                $o->weather_snow_cm,
                $o->weather_temp_max,
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        Storage::disk('s3')->put("output/batch_{$batchId}.csv", $csv);
    }

    private function isHoliday($date, $country)
    {
        $year = Carbon::parse($date)->year;

        if (!isset($this->holidayCache[$country][$year])) {

            try {
                $res = Http::get("https://date.nager.at/api/v3/PublicHolidays/$year/$country");

                $this->holidayCache[$country][$year] = $res->ok()
                    ? $res->json()
                    : [];

            } catch (\Exception $e) {
                $this->holidayCache[$country][$year] = [];
            }
        }

        foreach ($this->holidayCache[$country][$year] as $h) {
            if ($h['date'] === $date) return true;
        }

        return false;
    }

    private function defaultTimezone(string $country): string
    {
        return strtoupper($country) === 'DE'
            ? 'Europe/Berlin'
            : 'Asia/Kolkata';
    }
}
