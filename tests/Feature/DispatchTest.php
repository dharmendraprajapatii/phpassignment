<?php

namespace Tests\Feature;

use App\Models\DispatchBatch;
use App\Models\Order;
use App\Models\S3Import;
use App\Services\CsvOrderImportService;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Client\Request;

class DispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockExternalApis();
        Storage::fake('s3');
    }

    private function mockExternalApis($weatherOverride = null)
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => function (Request $request) {
                $url = $request->url();
                $isGermany = str_contains($url, 'countryCode=DE') || str_contains(strtolower($url), 'berlin');

                $result = $isGermany
                    ? [
                        'name' => 'Berlin',
                        'latitude' => 52.52,
                        'longitude' => 13.41,
                        'country_code' => 'DE',
                        'timezone' => 'Europe/Berlin',
                    ]
                    : [
                        'name' => 'Mumbai',
                        'latitude' => 19.07,
                        'longitude' => 72.87,
                        'country_code' => 'IN',
                        'timezone' => 'Asia/Kolkata',
                    ];

                return Http::response(['results' => [$result]], 200);
            },
            'api.open-meteo.com/*' => Http::response(
                $weatherOverride ?? [
                    'daily' => [
                        'time' => [
                            '2026-05-06',
                            '2026-05-07',
                            '2026-05-08',
                            '2026-05-09',
                            '2026-05-10',
                        ],
                        'precipitation_sum' => [50, 50, 50, 50, 50],
                        'snowfall_sum' => [0, 0, 0, 0, 0],
                        'temperature_2m_max' => [30, 30, 30, 30, 30],
                    ]
                ],
                200
            ),
            'date.nager.at/*' => Http::response([], 200),
            'api.frankfurter.app/*' => Http::response([
                'rates' => [
                    'INR' => 80,
                    'EUR' => 0.9
                ]
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_creates_dispatch_batch_from_api()
    {
        $payload = [
            'orders' => [
                [
                    'order_id' => 'O1',
                    'placed_at' => '2026-05-05T10:00:00+05:30',
                    'destination_address' => 'Mumbai, India',
                    'destination_country' => 'IN',
                    'total_value' => 100,
                    'total_value_currency' => 'USD',
                    'weight_grams' => 500,
                    'payment_mode' => 'cod'
                ]
            ]
        ];

        $response = $this->postJson('/api/dispatch-batches', $payload);

        $response->assertStatus(200)
                 ->assertJsonStructure(['batch_id']);

        $batchId = $response->json('batch_id');

        $this->assertDatabaseHas('orders', [
            'order_id' => 'O1',
            'batch_id' => $batchId
        ]);

        Storage::disk('s3')->assertExists("output/batch_{$batchId}.csv");

        $csv = Storage::disk('s3')->get("output/batch_{$batchId}.csv");

        $this->assertStringContainsString('batch_id,order_id,country,city,destination_timezone,dispatch_date,payment_mode,is_deferred,invoiced_value_local,invoiced_currency,weather_blocked,weather_rain_mm,weather_snow_cm,weather_temp_max', trim(explode("\n", $csv)[0]));
    }

    public function test_groups_orders_into_single_run()
    {
        $payload = [
            'orders' => [
                [
                    'order_id' => 'O1',
                    'placed_at' => '2026-05-05T10:00:00+05:30',
                    'destination_address' => 'Mumbai, India',
                    'destination_country' => 'IN',
                    'total_value' => 100,
                    'total_value_currency' => 'USD',
                    'weight_grams' => 500,
                    'payment_mode' => 'cod'
                ],
                [
                    'order_id' => 'O2',
                    'placed_at' => '2026-05-05T11:00:00+05:30',
                    'destination_address' => 'Mumbai, India',
                    'destination_country' => 'IN',
                    'total_value' => 200,
                    'total_value_currency' => 'USD',
                    'weight_grams' => 500,
                    'payment_mode' => 'cod'
                ]
            ]
        ];

        $response = $this->postJson('/api/dispatch-batches', $payload);
        $batchId = $response->json('batch_id');

        $get = $this->getJson("/api/dispatch-batches/{$batchId}");

        $get->assertStatus(200)
            ->assertJsonStructure([
                'batch_id',
                'runs' => [
                    [
                        'run_id',
                        'city',
                        'dispatch_date',
                        'orders'
                    ]
                ]
            ]);

        $runs = $get->json('runs');

        $this->assertCount(1, $runs);
        $this->assertCount(2, $runs[0]['orders']);
    }

    public function test_defers_order_when_weather_blocked()
    {
        // override weather to force block
        $this->mockExternalApis([
            'daily' => [
                'time' => ['2026-05-06', '2026-05-07'],
                'precipitation_sum' => [50, 0], // first day blocked
                'temperature_2m_max' => [30, 30],
            ]
        ]);

        $payload = [
            'orders' => [
                [
                    'order_id' => 'O3',
                    'placed_at' => '2026-05-05T10:00:00+05:30',
                    'destination_address' => 'Mumbai, India',
                    'destination_country' => 'IN',
                    'total_value' => 100,
                    'total_value_currency' => 'USD',
                    'weight_grams' => 500,
                    'payment_mode' => 'cod'
                ]
            ]
        ];

        $response = $this->postJson('/api/dispatch-batches', $payload);
        $batchId = $response->json('batch_id');

        $get = $this->getJson("/api/dispatch-batches/{$batchId}");

        $get->assertStatus(200);

        $this->assertNotEmpty($get->json('deferred_orders'));
    }

    public function test_defers_order_when_snow_blocked()
    {
        $this->mockExternalApis([
            'daily' => [
                'time' => ['2026-05-06', '2026-05-07'],
                'precipitation_sum' => [0, 0],
                'snowfall_sum' => [3, 0],
                'temperature_2m_max' => [0, 5],
            ]
        ]);

        $payload = [
            'orders' => [
                [
                    'order_id' => 'SNOW-1',
                    'placed_at' => '2026-05-05T10:00:00+01:00',
                    'destination_address' => 'Sonnenallee 142, 12059 Berlin.',
                    'destination_country' => 'DE',
                    'total_value' => 100,
                    'total_value_currency' => 'USD',
                    'weight_grams' => 500,
                    'payment_mode' => 'cod'
                ]
            ]
        ];

        $response = $this->postJson('/api/dispatch-batches', $payload);
        $batchId = $response->json('batch_id');

        $get = $this->getJson("/api/dispatch-batches/{$batchId}");

        $get->assertStatus(200);
        $this->assertNotEmpty($get->json('deferred_orders'));
        $this->assertSame('Berlin', $get->json('runs.0.city'));
    }

    public function test_persists_geocoded_city_and_destination_timezone()
    {
        $this->mockExternalApis([
            'daily' => [
                'time' => [
                    '2026-05-06',
                    '2026-05-07',
                    '2026-05-08',
                    '2026-05-09',
                    '2026-05-10',
                    '2026-05-11',
                    '2026-05-12',
                    '2026-05-13',
                    '2026-05-14',
                ],
                'precipitation_sum' => [0, 0, 0, 0, 0, 0, 0, 0, 0],
                'snowfall_sum' => [0, 0, 0, 0, 0, 0, 0, 0, 0],
                'temperature_2m_max' => [20, 21, 22, 20, 20, 20, 20, 20, 20],
            ]
        ]);

        $payload = [
            'orders' => [
                [
                    'order_id' => 'TZ-1',
                    'placed_at' => '2026-05-05T23:30:00-04:00',
                    'destination_address' => 'Sonnenallee 142, 12059 Berlin.',
                    'destination_country' => 'DE',
                    'total_value' => 100,
                    'total_value_currency' => 'USD',
                    'weight_grams' => 500,
                    'payment_mode' => 'cod'
                ]
            ]
        ];

        $response = $this->postJson('/api/dispatch-batches', $payload);
        $batchId = $response->json('batch_id');

        $get = $this->getJson("/api/dispatch-batches/{$batchId}");

        $get->assertStatus(200);
        $this->assertSame('Berlin', $get->json('runs.0.city'));

        $order = Order::where('batch_id', $batchId)->first();

        $this->assertSame('Europe/Berlin', $order->destination_timezone);
    }

    public function test_health_endpoint_works()
    {
        $response = $this->get('/api/healthz');

        $response->assertStatus(200)
                 ->assertJson(['status' => 'ok']);
    }

    public function test_s3_ingestion_is_idempotent()
    {
        $csv = implode("\n", [
            'order_id,placed_at,destination_address,destination_country,total_value,total_value_currency,weight_grams,payment_mode',
            'S3-1,2026-05-05T10:00:00+05:30,"Mumbai, India",IN,100,USD,500,cod',
        ]);

        Storage::disk('s3')->put('input/orders.csv', $csv);

        $this->artisan('app:process-s3-orders')->assertExitCode(0);
        $this->artisan('app:process-s3-orders')->assertExitCode(0);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('s3_imports', 1);
        $this->assertDatabaseHas('s3_imports', [
            'path' => 'input/orders.csv',
            'status' => 'processed',
            'orders_count' => 1,
        ]);

        $order = Order::first();

        $this->assertNull($order->batch_id);
        $this->assertSame('s3', $order->ingestion_source);
        Storage::disk('s3')->assertMissing('input/orders.csv');
        Storage::disk('s3')->assertExists('processed/orders.csv');
    }

    public function test_daily_command_batches_only_pending_orders_from_previous_24_hours()
    {
        $recentOrder = Order::create([
            'batch_id' => null,
            'order_id' => 'PENDING-1',
            'placed_at' => '2026-05-05 10:00:00',
            'address' => 'Mumbai, India',
            'country' => 'IN',
            'value' => 100,
            'currency' => 'USD',
            'weight' => 500,
            'payment_mode' => 'cod',
            'ingestion_source' => 's3',
            'source_reference' => 'input/orders.csv',
        ]);

        $recentOrder->created_at = CarbonImmutable::now('UTC')->subHours(2);
        $recentOrder->updated_at = CarbonImmutable::now('UTC')->subHours(2);
        $recentOrder->save();

        $oldOrder = Order::create([
            'batch_id' => null,
            'order_id' => 'PENDING-OLD',
            'placed_at' => '2026-05-04 10:00:00',
            'address' => 'Mumbai, India',
            'country' => 'IN',
            'value' => 120,
            'currency' => 'USD',
            'weight' => 500,
            'payment_mode' => 'cod',
            'ingestion_source' => 's3',
            'source_reference' => 'input/old-orders.csv',
        ]);

        $oldOrder->created_at = CarbonImmutable::now('UTC')->subHours(30);
        $oldOrder->updated_at = CarbonImmutable::now('UTC')->subHours(30);
        $oldOrder->save();

        $this->artisan('app:process-pending-dispatches')->assertExitCode(0);

        $this->assertDatabaseCount('dispatch_batches', 1);

        $batch = DispatchBatch::first();
        $recentOrder->refresh();
        $oldOrder->refresh();

        $this->assertSame('scheduled', $batch->source);
        $this->assertSame($batch->id, $recentOrder->batch_id);
        $this->assertNull($oldOrder->batch_id);
    }

    public function test_marks_s3_import_failed_for_malformed_csv()
    {
        $csv = implode("\n", [
            'order_id,placed_at,destination_address,destination_country,total_value,total_value_currency,weight_grams,payment_mode',
            'BAD-1,2026-05-05T10:00:00+05:30,"Mumbai, India",IN,100',
        ]);

        Storage::disk('s3')->put('input/bad-orders.csv', $csv);

        try {
            app(CsvOrderImportService::class)->ingestFromS3('input/bad-orders.csv');
            $this->fail('Expected malformed CSV import to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Malformed CSV row', $e->getMessage());
        }

        $this->assertDatabaseHas('s3_imports', [
            'path' => 'input/bad-orders.csv',
            'status' => 'failed',
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_defers_order_when_weather_api_fails()
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => Http::response([
                'results' => [[
                    'name' => 'Mumbai',
                    'latitude' => 19.07,
                    'longitude' => 72.87,
                    'country_code' => 'IN',
                    'timezone' => 'Asia/Kolkata',
                ]]
            ], 200),
            'api.open-meteo.com/*' => Http::response([], 500),
            'date.nager.at/*' => Http::response([], 200),
            'api.frankfurter.app/*' => Http::response([
                'rates' => ['INR' => 80]
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/dispatch-batches', [
            'orders' => [[
                'order_id' => 'WEATHER-FAIL-1',
                'placed_at' => '2026-05-05T10:00:00+05:30',
                'destination_address' => 'Mumbai, India',
                'destination_country' => 'IN',
                'total_value' => 100,
                'total_value_currency' => 'USD',
                'weight_grams' => 500,
                'payment_mode' => 'cod'
            ]]
        ]);

        $batchId = $response->json('batch_id');
        $get = $this->getJson("/api/dispatch-batches/{$batchId}");

        $get->assertStatus(200);
        $this->assertNotEmpty($get->json('deferred_orders'));
    }
}
