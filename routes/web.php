<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::post('/settlements', [ExpenseController::class, 'storeSettlement'])->name('settlements.store');
Route::get('/wallet-snapshots', [ExpenseController::class, 'getWalletSnapshots'])->name('wallet.snapshots');

// Statement History routes
Route::get('/statements/user/{userId}', [ExpenseController::class, 'userStatementView'])->name('statements.user');
Route::get('/api/statements/user/{userId}', [ExpenseController::class, 'apiStatementHistory'])->name('api.statements.user');
Route::get('/api/statements/all', [ExpenseController::class, 'getAllStatementHistory'])->name('api.statements.all');
Route::post('/api/statements/regenerate', [ExpenseController::class, 'regenerateStatementRecords'])->name('api.statements.regenerate');
Route::post('/api/statements/regenerate-simplified', [ExpenseController::class, 'regenerateSimplifiedStatements'])->name('api.statements.regenerate-simplified');
Route::get('/statements/regenerate', [ExpenseController::class, 'regenerateSimplifiedStatements'])->name('statements.regenerate');

// Debug routes
Route::get('/debug/balance', [ExpenseController::class, 'debugBalance'])->name('debug.balance');
Route::get('/debug/breakdowns', [ExpenseController::class, 'debugBreakdowns'])->name('debug.breakdowns');
Route::get('/debug/test-scenarios', [ExpenseController::class, 'testCalculationScenarios'])->name('debug.test-scenarios');
Route::get('/debug/validate-implementation', [ExpenseController::class, 'validateImplementation'])->name('debug.validate-implementation');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings/users', [SettingsController::class, 'updateUsers'])->name('settings.update-users');
Route::delete('/settings/users/{user}', [SettingsController::class, 'deleteUser'])->name('settings.delete-user');
Route::post('/settings/users/{user}/reactivate', [SettingsController::class, 'reactivateUser'])->name('settings.reactivate-user');

