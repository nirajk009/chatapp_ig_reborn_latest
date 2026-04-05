<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\VisitorChatController;
use Illuminate\Support\Facades\Route;

// ─── Visitor Routes ───
Route::prefix('visitor')->group(function () {
    Route::post('/init', [VisitorChatController::class, 'init']);
    Route::post('/signup', [VisitorChatController::class, 'signup']);
    Route::post('/login', [VisitorChatController::class, 'login']);
    Route::get('/messages', [VisitorChatController::class, 'getMessages']);
    Route::post('/messages', [VisitorChatController::class, 'sendMessage']);
    Route::post('/realtime/auth', [VisitorChatController::class, 'realtimeAuth']);
    Route::get('/poll', [VisitorChatController::class, 'poll']);
    Route::post('/save-info', [VisitorChatController::class, 'saveInfo']);

    // Logged-in visitor routes
    Route::get('/contacts', [VisitorChatController::class, 'contacts']);
    Route::get('/search-users', [VisitorChatController::class, 'searchUsers']);
    Route::post('/start-chat', [VisitorChatController::class, 'startChat']);
    Route::get('/conversations/{conversationId}/messages', [VisitorChatController::class, 'conversationMessages']);
    Route::post('/conversations/{conversationId}/typing', [VisitorChatController::class, 'typing']);
    Route::post('/conversations/{conversationId}/read', [VisitorChatController::class, 'markConversationRead']);
    Route::get('/conversations/{conversationId}/poll', [VisitorChatController::class, 'pollConversation']);
});

// ─── Admin Routes ───
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::get('/conversations', [AdminController::class, 'conversations']);
    Route::get('/visitors/{visitorId}/profile', [AdminController::class, 'visitorProfile']);
    Route::get('/conversations/{visitorId}/messages', [AdminController::class, 'getMessages']);
    Route::post('/conversations/{visitorId}/messages', [AdminController::class, 'sendMessage']);
    Route::post('/conversations/{visitorId}/typing', [AdminController::class, 'typing']);
    Route::post('/conversations/{visitorId}/read', [AdminController::class, 'markConversationRead']);
    Route::post('/realtime/auth', [AdminController::class, 'realtimeAuth']);
    Route::get('/poll', [AdminController::class, 'poll']);
    Route::get('/conversations/{visitorId}/poll', [AdminController::class, 'pollConversation']);
});
