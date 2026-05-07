<?php

namespace App\Services;

use App\Models\Order;
use App\Models\S3Import;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CsvOrderImportService
{
    private const REQUIRED_HEADERS = [
        'order_id',
        'placed_at',
        'destination_address',
        'destination_country',
        'total_value',
        'total_value_currency',
        'weight_grams',
        'payment_mode',
    ];

    public function ingestFromS3(string $path): S3Import
    {
        $existing = S3Import::where('path', $path)->first();

        if ($existing && $existing->status === 'processed') {
            return $existing;
        }

        $import = $existing ?? S3Import::create([
            'path' => $path,
            'status' => 'processing',
        ]);

        $import->status = 'processing';
        $import->error_message = null;
        $import->save();

        try {
            $rows = $this->readRows($path);

            foreach ($rows as $row) {
                Order::create([
                    'batch_id' => null,
                    'order_id' => trim($row['order_id']),
                    'placed_at' => Carbon::parse($row['placed_at'])->toDateTimeString(),
                    'address' => trim($row['destination_address']),
                    'country' => trim($row['destination_country']),
                    'value' => (float) $row['total_value'],
                    'currency' => trim($row['total_value_currency']),
                    'weight' => (int) $row['weight_grams'],
                    'payment_mode' => $row['payment_mode'] ?? 'prepaid',
                    'ingestion_source' => 's3',
                    'source_reference' => $path,
                ]);
            }

            $this->archiveFile($path);

            $import->status = 'processed';
            $import->orders_count = count($rows);
            $import->ingested_at = now();
            $import->processed_at = now();
            $import->save();

            return $import;
        } catch (\Throwable $e) {
            $import->status = 'failed';
            $import->error_message = $e->getMessage();
            $import->save();

            throw $e;
        }
    }

    public function readRows(string $path): array
    {
        if (!Storage::disk('s3')->exists($path)) {
            throw new RuntimeException("S3 file not found: {$path}");
        }

        $content = Storage::disk('s3')->get($path);
        $lines = array_filter(array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($content))));

        if (empty($lines)) {
            throw new RuntimeException("CSV file is empty: {$path}");
        }

        $header = array_map('trim', array_shift($lines));
        $missingHeaders = array_diff(self::REQUIRED_HEADERS, $header);

        if (!empty($missingHeaders)) {
            throw new RuntimeException('Missing CSV headers: '.implode(', ', $missingHeaders));
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            if (count($line) !== count($header)) {
                throw new RuntimeException('Malformed CSV row at line '.($index + 2));
            }

            $row = array_combine($header, $line);

            if (!$row || empty($row['order_id']) || empty($row['placed_at'])) {
                throw new RuntimeException('Missing required order fields at line '.($index + 2));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function archiveFile(string $path): void
    {
        $archivedPath = preg_replace('#^input/#', 'processed/', $path, 1);

        if (!$archivedPath || $archivedPath === $path) {
            $archivedPath = 'processed/'.basename($path);
        }

        Storage::disk('s3')->copy($path, $archivedPath);
        Storage::disk('s3')->delete($path);
    }
}
