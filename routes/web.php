<?php

use App\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::post('/settlements', [ExpenseController::class, 'storeSettlement'])->name('settlements.store');
