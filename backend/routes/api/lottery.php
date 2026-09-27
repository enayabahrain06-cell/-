<?php

use App\Http\Controllers\Api\Lottery\LotteryController;
use Illuminate\Support\Facades\Route;

// Lottery (inside auth:sanctum group)
Route::get('lotteries', [LotteryController::class, 'index']);
Route::post('lotteries', [LotteryController::class, 'store']);
Route::get('lotteries/{lottery}', [LotteryController::class, 'show']);
Route::put('lotteries/{lottery}', [LotteryController::class, 'update']);
Route::post('lotteries/{lottery}/pool/sync', [LotteryController::class, 'syncPool']);
Route::post('lotteries/{lottery}/run', [LotteryController::class, 'run']);
Route::post('lotteries/{lottery}/results/{result}/move', [LotteryController::class, 'move']);
Route::post('lotteries/{lottery}/approve', [LotteryController::class, 'approve']);
Route::post('lotteries/{lottery}/cancel', [LotteryController::class, 'cancel']);
