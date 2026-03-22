<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VisitorChatController;
use App\Http\Controllers\Api\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Visitor endpoints (token via X-Visitor-Token header)
Route::prefix('visitor')->group(function () {
    Route::post('/init', [VisitorChatController::class, 'init']);
    Route::get('/messages', [VisitorChatController::class, 'getMessages']);
    Route::post('/messages', [VisitorChatController::class, 'sendMessage']);
    Route::get('/poll', [VisitorChatController::class, 'poll']);
    Route::post('/save-info', [VisitorChatController::class, 'saveInfo']);
});

// Admin endpoints (token via Authorization: Bearer header)
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::get('/conversations', [AdminController::class, 'conversations']);
    Route::get('/conversations/{visitor_id}/messages', [AdminController::class, 'getMessages']);
    Route::post('/conversations/{visitor_id}/messages', [AdminController::class, 'sendMessage']);
    Route::get('/poll', [AdminController::class, 'poll']);
    Route::get('/conversations/{visitor_id}/poll', [AdminController::class, 'pollConversation']);
});
