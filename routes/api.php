<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BalanceController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupMemberController;
use App\Http\Controllers\Api\V1\SettlementController;
use App\Http\Controllers\Api\V1\StatementController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::prefix('v1')->group(function () {
    // Authentication routes (public)
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [AuthController::class, 'googleLogin']);
    Route::post('/auth/apple', [AuthController::class, 'appleLogin']);
    Route::post('/auth/password/send-code', [AuthController::class, 'sendPasswordResetCode']);
    Route::post('/auth/password/reset', [AuthController::class, 'resetPassword']);
    Route::get('/invitations/accept/{token}', [GroupController::class, 'acceptInvitation']);

    // Protected routes (require auth:sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
        Route::delete('/auth/me', [AuthController::class, 'deleteAccount']);
        Route::post('/auth/email/send-code', [AuthController::class, 'sendVerificationCode']);
        Route::post('/auth/email/verify', [AuthController::class, 'verifyEmailCode']);

        // Group management
        Route::get('/groups', [GroupController::class, 'index']);
        Route::post('/groups', [GroupController::class, 'store']);
        Route::post('/groups/join', [GroupController::class, 'join']);
        Route::post('/groups/join/qr', [GroupController::class, 'joinByQr']);

        // Group-scoped routes (require membership)
        Route::middleware('ensure.group.member')->group(function () {
            // Group info
            Route::get('/groups/{group}', [GroupController::class, 'show']);
            Route::get('/groups/{group}/join-qr', [GroupController::class, 'qrJoinCode']);
            Route::patch('/groups/{group}', [GroupController::class, 'update']);
            Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
            Route::post('/groups/{group}/rename', [GroupController::class, 'update']);
            Route::post('/groups/{group}/delete', [GroupController::class, 'destroy']);
            Route::get('/groups/{group}/members', [GroupController::class, 'members']);
            Route::get('/groups/{group}/categories', [GroupController::class, 'categories']);
            Route::post('/groups/{group}/categories', [GroupController::class, 'updateCategories']);
            Route::post('/groups/{group}/members/add-by-email', [GroupController::class, 'addMemberByEmail']);
            Route::post('/groups/{group}/members/add', [GroupController::class, 'addMemberByEmail']);
            Route::post('/groups/{group}/members', [GroupController::class, 'addMemberByEmail']);
            Route::post('/groups/{group}/members/{user}/remove', [GroupMemberController::class, 'remove']);
            Route::post('/groups/{group}/members/deactivate', [GroupMemberController::class, 'deactivate']);
            Route::post('/groups/{group}/members/reactivate', [GroupMemberController::class, 'reactivate']);
            Route::patch('/groups/{group}/members/me/notification-preferences', [GroupMemberController::class, 'updateNotificationPreferences']);

            // Expenses
            Route::get('/groups/{group}/expenses', [ExpenseController::class, 'index']);
            Route::post('/groups/{group}/expenses', [ExpenseController::class, 'store']);
            Route::get('/groups/{group}/expenses/{expense}', [ExpenseController::class, 'show']);
            Route::patch('/groups/{group}/expenses/{expense}', [ExpenseController::class, 'update']);
            Route::delete('/groups/{group}/expenses/{expense}', [ExpenseController::class, 'destroy']);
            Route::patch('/groups/{group}/expenses/{expense}/participants', [ExpenseController::class, 'updateParticipants']);
            Route::post('/groups/{group}/expenses/{expense}/receipt', [ExpenseController::class, 'uploadReceipt']);
            Route::delete('/groups/{group}/expenses/{expense}/receipt', [ExpenseController::class, 'deleteReceipt']);

            // Settlements
            Route::get('/groups/{group}/settlements', [SettlementController::class, 'index']);
            Route::post('/groups/{group}/settlements', [SettlementController::class, 'store']);
            Route::get('/groups/{group}/settlements/{settlement}', [SettlementController::class, 'show']);
            Route::post('/groups/{group}/settlements/{settlement}/update', [SettlementController::class, 'update']);
            Route::delete('/groups/{group}/settlements/{settlement}', [SettlementController::class, 'destroy']);

            // Balance & Statements
            Route::get('/groups/{group}/balance', [BalanceController::class, 'snapshot']);
            Route::get('/groups/{group}/statements', [StatementController::class, 'index']);
        });
    });
});
