<?php

namespace App\Http\Controllers;

use App\Models\DispatchBatch;
use App\Models\Order;
use App\Models\DispatchRun;
use App\Services\CsvOrderImportService;
use App\Services\DispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class DispatchController extends Controller
{
    public function store(Request $request)
    {
        $payload = $request->validate([
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.order_id' => ['required', 'string'],
            'orders.*.placed_at' => ['required', 'date'],
            'orders.*.destination_address' => ['required', 'string'],
            'orders.*.destination_country' => ['required', 'in:IN,DE'],
            'orders.*.total_value' => ['required', 'numeric'],
            'orders.*.total_value_currency' => ['required', 'string', 'size:3'],
            'orders.*.weight_grams' => ['required', 'integer', 'min:1'],
            'orders.*.payment_mode' => ['required', 'in:prepaid,cod'],
        ]);

        $batch = DispatchBatch::create([
            'id' => (string) Str::uuid(),
            'source' => 'api',
        ]);

        foreach ($payload['orders'] as $o) {
            Order::create([
                'batch_id' => $batch->id,
                'order_id' => $o['order_id'],
                'placed_at' => $o['placed_at'],
                'address' => $o['destination_address'],
                'country' => $o['destination_country'],
                'value' => $o['total_value'],
                'currency' => $o['total_value_currency'],
                'weight' => $o['weight_grams'],
                'payment_mode' => $o['payment_mode'],
                'ingestion_source' => 'api',
            ]);
        }

        app(DispatchService::class)->process($batch->id);

        return response()->json(['batch_id' => $batch->id]);
    }

    public function show($batchId)
    {
        $batch = DispatchBatch::findOrFail($batchId);
        $runs = DispatchRun::where('batch_id', $batchId)->get();

        $responseRuns = [];

        foreach ($runs as $run) {

            $orders = Order::where('batch_id', $batchId)
                ->where('city', $run->city)
                ->where('dispatch_date', $run->dispatch_date)
                ->get();

            $responseRuns[] = [
                'run_id' => $run->id,
                'city' => $run->city,
                'country' => $run->country,
                'dispatch_date' => $run->dispatch_date,
                'orders' => $orders,
                'weather_summary' => json_decode($run->weather_summary, true),
                'total_invoiced_value_local' => (float) $run->total_value,
                'total_invoiced_value_currency' => $run->currency
            ];
        }

        return response()->json([
            'batch_id' => $batch->id,
            'processed_at' => $batch->processed_at,
            'runs' => $responseRuns,
            'deferred_orders' => Order::where('batch_id', $batchId)
                ->where('is_deferred', true)
                ->get(),
            'failed_orders' => $batch->failed_orders ?? []
        ]);
    }

    public function recompute($id)
    {
        app(DispatchService::class)->process($id);

        return response()->json(['status' => 'recomputed']);
    }

    public function uploadCsv(Request $request, CsvOrderImportService $csvOrderImportService)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');

        $filename = time() . '_' . $file->getClientOriginalName();

        $path = Storage::disk('s3')->putFileAs('input', $file, $filename);

        $import = $csvOrderImportService->ingestFromS3($path);

        return response()->json([
            'message' => 'uploaded',
            'path' => $import->path,
            'orders_ingested' => $import->orders_count,
            'status' => $import->status,
        ], 202);
    }
}
