<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::post('/settlements', [ExpenseController::class, 'storeSettlement'])->name('settlements.store');
Route::get('/wallet-snapshots', [ExpenseController::class, 'getWalletSnapshots'])->name('wallet.snapshots');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings/users', [SettingsController::class, 'updateUsers'])->name('settings.update-users');
Route::delete('/settings/users/{user}', [SettingsController::class, 'deleteUser'])->name('settings.delete-user');
Route::post('/settings/users/{user}/reactivate', [SettingsController::class, 'reactivateUser'])->name('settings.reactivate-user');

