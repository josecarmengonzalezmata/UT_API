<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CycleController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GroupController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/profile/stats', [AuthController::class, 'profileStats']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::patch('/password', [AuthController::class, 'updatePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Allow public read access to groups so the frontend can list available groups without authentication.
Route::get('/groups', [GroupController::class, 'index']);
Route::get('/groups/{group}', [GroupController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    Route::apiResource('forms', FormController::class)->only(['index', 'show', 'update']);
    Route::apiResource('cycles', CycleController::class);
    Route::apiResource('users', UserController::class);
    // Protected group mutations (create, update, delete)
    Route::apiResource('groups', GroupController::class)->except(['index','show']);
    Route::apiResource('documents', DocumentController::class);
    Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');

    Route::get('/documents/{document}/history', [DocumentController::class, 'history']);
    Route::patch('/documents/{document}/review', [DocumentController::class, 'review']);
    Route::patch('/documents/{document}/return', [DocumentController::class, 'returnDocument']);

    Route::get('/conversations', [MessageController::class, 'index']);
    Route::post('/conversations', [MessageController::class, 'storeConversation']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'storeMessage']);
    Route::delete('/conversations/{conversation}/messages/{message}', [MessageController::class, 'destroy']);
    Route::patch('/conversations/{conversation}/read', [MessageController::class, 'markAsRead']);
});
