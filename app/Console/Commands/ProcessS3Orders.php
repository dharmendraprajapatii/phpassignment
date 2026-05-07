<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Services\CsvOrderImportService;

class ProcessS3Orders extends Command
{
    protected $signature = 'app:process-s3-orders';
    protected $description = 'Ingest orders from S3 CSV into pending intake';

    public function handle(CsvOrderImportService $csvOrderImportService): int
    {
        $files = Storage::disk('s3')->files('input');

        foreach ($files as $file) {
            $import = $csvOrderImportService->ingestFromS3($file);
            $this->info("Ingested file: {$import->path} ({$import->orders_count} orders)");
        }

        return self::SUCCESS;
    }
}
