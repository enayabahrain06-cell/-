<?php

use App\Http\Controllers\Api\Books\BookController;
use Illuminate\Support\Facades\Route;

// الكتب and متابعة الكتب (books.view reads, books.manage writes), inside the auth:sanctum group.
Route::get('books/{book}/followup', [BookController::class, 'followup']);
Route::post('books/{book}/deliveries', [BookController::class, 'deliver']);
Route::delete('books/{book}/deliveries/{delivery}', [BookController::class, 'undo']);
Route::apiResource('books', BookController::class)->except('show');
