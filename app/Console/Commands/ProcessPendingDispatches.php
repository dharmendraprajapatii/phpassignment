<?php

namespace App\Console\Commands;

use App\Models\DispatchBatch;
use App\Models\Order;
use App\Services\DispatchService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProcessPendingDispatches extends Command
{
    protected $signature = 'app:process-pending-dispatches';

    protected $description = 'Create a dispatch batch from unprocessed orders received in the previous 24 hours';

    public function handle(DispatchService $dispatchService): int
    {
        $windowEndIst = CarbonImmutable::now('Asia/Kolkata');
        $windowStartIst = $windowEndIst->subDay();

        $orders = Order::whereNull('batch_id')
            ->whereBetween('created_at', [
                $windowStartIst->utc()->toDateTimeString(),
                $windowEndIst->utc()->toDateTimeString(),
            ])
            ->orderBy('created_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No pending orders found for the previous 24 hours.');

            return self::SUCCESS;
        }

        $batch = DispatchBatch::create([
            'id' => (string) Str::uuid(),
            'source' => 'scheduled',
        ]);

        foreach ($orders as $order) {
            $order->batch_id = $batch->id;
            $order->save();
        }

        $dispatchService->process($batch->id);

        $this->info("Processed scheduled batch {$batch->id} for {$orders->count()} orders.");

        return self::SUCCESS;
    }
}
