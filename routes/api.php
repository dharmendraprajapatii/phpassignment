<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\DispatchController;

Route::post('/dispatch-batches', [DispatchController::class, 'store']);
Route::get('/dispatch-batches/{id}', [DispatchController::class, 'show']);
Route::post('/dispatch-batches/{id}/recompute', [DispatchController::class, 'recompute']);
Route::post('/dispatch-batches/upload-csv', [DispatchController::class, 'uploadCsv']);

Route::get('/healthz', function () {
    return response()->json([
        'status' => 'ok',
        'time' => now()
    ]);
});
