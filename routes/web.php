<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LegacyImportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\PublicMediaController;
use Illuminate\Support\Facades\Route;

Route::view('/terms-and-conditions', 'legal.terms')->name('legal.terms');
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
Route::get('/reset-password', [PasswordResetController::class, 'show'])->name('password.reset.show');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.reset.update');
Route::get('/media/public/{path}', [PublicMediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.public');

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');

    Route::middleware('admin.auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/users', [DashboardController::class, 'users'])->name('users');
        Route::get('/users/{user}/edit', [DashboardController::class, 'editUser'])->name('users.edit');
        Route::post('/users/{user}/edit', [DashboardController::class, 'updateUser'])->name('users.update');
        Route::post('/users/{user}/toggle', [DashboardController::class, 'toggleUser'])->name('users.toggle');
        Route::post('/users/{user}/delete', [DashboardController::class, 'deleteUser'])->name('users.delete');
        Route::get('/groups', [DashboardController::class, 'groups'])->name('groups');
        Route::get('/groups/{group}/edit', [DashboardController::class, 'editGroup'])->name('groups.edit');
        Route::post('/groups/{group}/edit', [DashboardController::class, 'updateGroup'])->name('groups.update');
        Route::get('/groups/{group}/records', [DashboardController::class, 'groupRecords'])->name('groups.records');
        Route::post('/groups/{group}/delete', [DashboardController::class, 'deleteGroup'])->name('groups.delete');
        Route::get('/groups/{group}', [DashboardController::class, 'showGroup'])->name('groups.show');
        Route::get('/api-access', [DashboardController::class, 'apiDocs'])->name('api-docs');
        Route::match(['get', 'post'], '/tools/legacy-import', LegacyImportController::class)
            ->name('tools.legacy-import');

        // Settings
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings/smtp', [SettingsController::class, 'updateSmtp'])->name('settings.smtp');
        Route::post('/settings/smtp/test', [SettingsController::class, 'testSmtp'])->name('settings.smtp.test');
        Route::post('/settings/optimize-clear', [SettingsController::class, 'optimizeClear'])->name('settings.optimize-clear');
        Route::get('/logs', [SettingsController::class, 'logs'])->name('logs');
        Route::post('/logs/clear', [SettingsController::class, 'clearLogs'])->name('logs.clear');
    });
});
