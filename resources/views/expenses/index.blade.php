@extends('layouts.app')

@section('title', 'SplitMate - Simple Expense Splitter')
@section('subtitle', 'Simple expense splitting among ' . max($users->count(), 0) . ' people')

@section('content')
    <!-- Wallet Overview -->
        <div class="bg-white rounded-2xl shadow-lg p-4 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">💰 Wallet Status</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4" style="width: 100%; max-width: 100%; overflow-x: hidden;">
                @if(count($balances) > 0)
                    @foreach($balances as $userId => $balance)
                    <x-wallet-card :balance="$balance" :users="$users" />
                    @endforeach
                @else
                    <div class="col-span-3 text-center py-8">
                        <p class="text-gray-500">No wallet data available. Please add some users and expenses.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Quick Action Buttons -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Add Expense Card -->
            <div class="bg-white rounded-2xl shadow-lg p-6">
                <div class="text-center mb-6">
                    <div class="text-4xl mb-2">💸</div>
                    <h2 class="text-2xl font-bold text-gray-800">Add New Expense</h2>
                <p class="text-gray-600">Split a bill between all {{ max($users->count(), 0) }} of you</p>
                </div>
                
                <form action="{{ route('expenses.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <input type="text" name="description" required 
                               class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500"
                               placeholder="What did you buy? (e.g., Groceries, Dinner)">
                    </div>

                    <div class="flex gap-3">
                        <div class="flex-1">
                            <input type="number" name="amount" step="0.01" min="0.01" required 
                                   class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500"
                                   placeholder="Total amount">
                        </div>
                        <div class="text-center text-gray-500 text-sm pt-3">
                        ÷ {{ max($users->count(), 1) }} = $<span id="perPerson">0.00</span> each
                    </div>
                    </div>

                    <div>
                        <select name="paid_by_user_id" required 
                                class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500">
                            <option value="">Who paid for this?</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>

                <!-- Auto Debt Reduction Info -->
                <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="text-2xl">💡</div>
                        <div>
                            <span class="text-lg font-medium text-blue-800">Automatic Debt Reduction</span>
                            <p class="text-sm text-blue-700">If you have existing debts, your share of this expense will automatically reduce them. No manual allocation needed!</p>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="expense_date" required 
                           class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500"
                           value="{{ date('Y-m-d') }}">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Photo <span class="text-red-500">*</span></label>
                    
                    <!-- Primary file input (hidden by default, shown when validation fails) -->
                    <input type="file" name="receipt_photo" id="receiptFile" accept="image/*" 
                           class="hidden w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500">
                    
                    <!-- Hidden file input for camera -->
                    <input type="file" id="receiptCamera" accept="image/*" capture="environment" 
                           class="hidden">
                    
                    <div class="flex gap-2">
                        <button type="button" onclick="selectFromGallery('receiptFile')" 
                                class="flex-1 bg-gray-100 text-gray-700 py-3 px-4 rounded-xl text-sm hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                            📁 Choose from Gallery
                        </button>
                        <button type="button" onclick="takePhoto('receiptCamera', 'receiptFile')" 
                                class="flex-1 bg-blue-100 text-blue-700 py-3 px-4 rounded-xl text-sm hover:bg-blue-200 transition-colors flex items-center justify-center gap-2">
                            📷 Take Photo
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Upload receipt or take a photo for proof</p>
                    <div id="receipt-status" class="text-sm mt-1 text-gray-500">No file selected</div>
                </div>

                    <button type="submit" 
                        class="w-full bg-blue-500 text-white text-lg font-semibold py-3 rounded-xl hover:bg-blue-600 transition-colors">
                        💸 Add Expense
                    </button>
                </form>
            </div>

        <!-- Add Settlement Card -->
            <div class="bg-white rounded-2xl shadow-lg p-6">
                <div class="text-center mb-6">
                <div class="text-4xl mb-2">💳</div>
                <h2 class="text-2xl font-bold text-gray-800">Record Settlement</h2>
                <p class="text-gray-600">Mark a debt as paid</p>
                </div>
                
                <form action="{{ route('settlements.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                <div class="flex gap-3">
                    <select name="from_user_id" required 
                            class="flex-1 text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500">
                        <option value="">Who paid?</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <div class="text-center text-gray-500 text-2xl pt-2">→</div>
                    <select name="to_user_id" required 
                            class="flex-1 text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500">
                        <option value="">Who received?</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-3">
                    <div class="flex-1 min-h-[80px]">
                        <input type="number" name="amount" step="0.01" min="0.01" required 
                               class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500"
                               placeholder="Amount paid back">
                        <div id="max-amount-hint" class="text-xs text-gray-500 mt-1 h-4 hidden">
                            Maximum: $<span id="max-amount-value">0.00</span>
                        </div>
                    </div>
                    <div class="flex-1 min-h-[80px]">
                        <input type="date" name="settlement_date" required 
                               class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500"
                               value="{{ date('Y-m-d') }}">
                        <div class="h-4"></div> <!-- Spacer to match the hint height -->
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Screenshot <span class="text-red-500">*</span></label>
                    
                    <!-- Primary file input (hidden by default, shown when validation fails) -->
                    <input type="file" name="payment_screenshot" id="paymentScreenshotFile" accept="image/*" 
                           class="hidden w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500">
                    
                    <!-- Hidden file input for camera -->
                    <input type="file" id="paymentCamera" accept="image/*" capture="environment" 
                           class="hidden">
                    
                    <div class="flex gap-2">
                        <button type="button" onclick="selectFromGallery('paymentScreenshotFile')" 
                                class="flex-1 bg-gray-100 text-gray-700 py-3 px-4 rounded-xl text-sm hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                            📁 Choose from Gallery
                        </button>
                        <button type="button" onclick="takePhoto('paymentCamera', 'paymentScreenshotFile')" 
                                class="flex-1 bg-green-100 text-green-700 py-3 px-4 rounded-xl text-sm hover:bg-green-200 transition-colors flex items-center justify-center gap-2">
                            📷 Take Photo
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Upload payment proof (bank transfer, UPI screenshot, etc.)</p>
                    <div id="payment-status" class="text-sm mt-1 text-gray-500">No file selected</div>
                </div>

                <!-- Payment Preview -->
                <div id="payment-preview" class="hidden bg-blue-50 border-2 border-blue-200 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-blue-600">🔮</span>
                        <span class="font-semibold text-blue-800">Payment Preview</span>
                    </div>
                    <div id="preview-content" class="text-sm text-blue-700">
                        <!-- Preview content will be populated by JavaScript -->
                    </div>
                </div>

                    <button type="submit" 
                        class="w-full bg-green-500 text-white text-lg font-semibold py-3 rounded-xl hover:bg-green-600 transition-colors">
                    💳 Record Settlement
                    </button>
                </form>
            </div>
        </div>

    <!-- Recent Activity -->
    <div class="bg-white rounded-2xl shadow-lg p-6">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">📋 Recent Activity</h2>
        
        <div class="space-y-4">
            @php
                // Combine and sort all transactions by creation time
                $allTransactions = collect();
                
                // Add expenses with type identifier
                foreach($expenses as $expense) {
                    $allTransactions->push((object)[
                        'type' => 'expense',
                        'created_at' => $expense->created_at,
                        'data' => $expense
                    ]);
                }
                
                // Add settlements with type identifier
                foreach($settlements as $settlement) {
                    $allTransactions->push((object)[
                        'type' => 'settlement',
                        'created_at' => $settlement->created_at,
                        'data' => $settlement
                    ]);
                }
                
                // Sort by creation time descending (most recent activity first)
                $allTransactions = $allTransactions->sortByDesc('created_at');
            @endphp

            @forelse($allTransactions as $transaction)
                @if($transaction->type === 'expense')
                    @php 
                        $expense = $transaction->data;
                        $details = $expenseDetails[$expense->id] ?? null;
                    @endphp
                    <div class="bg-blue-50 border-l-4 border-blue-500 rounded-xl p-4 mb-4">
                        <!-- Main expense info -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <p class="font-bold text-lg text-gray-800">{{ $expense->description }}</p>
                                </div>
                                <p class="text-gray-600">
                                    Paid by {{ $expense->paidByUser->name }} • {{ $expense->expense_date->format('M d, Y') }}
                                    @if($expense->receipt_photo && file_exists(public_path('uploads/' . $expense->receipt_photo)))
                                        • <a href="/uploads/{{ $expense->receipt_photo }}" target="_blank" 
                                             class="text-blue-600 hover:underline">📷 Receipt</a>
                                    @elseif($expense->receipt_photo)
                                        • <span class="text-gray-400">📷 Receipt (missing)</span>
                                    @endif
                                    <br><span class="text-xs text-gray-500">Added {{ $expense->created_at->diffForHumans() }}</span>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-xl text-gray-800">${{ number_format($expense->amount, 2) }}</p>
                                <p class="text-sm text-gray-600">${{ number_format($expense->amount_per_person, 2) }} each</p>
                            </div>
                        </div>

                        @if($details)
                            <!-- Step-by-step breakdown -->
                            <div class="bg-white rounded-lg border border-blue-200">
                                <button onclick="toggleBreakdown({{ $expense->id }})" 
                                        class="w-full p-4 text-left font-semibold text-gray-800 flex items-center justify-between hover:bg-gray-50 transition-colors border-b border-gray-200">
                                    <div class="flex items-center gap-2">
                                        <span class="text-blue-600">📊</span> Step-by-Step Breakdown
                                        <span class="text-xs text-gray-500 font-normal">(Click to expand)</span>
                                    </div>
                                    <span id="breakdown-icon-{{ $expense->id }}" class="text-gray-500 transform transition-transform duration-200">▼</span>
                                </button>
                                
                                <div id="breakdown-content-{{ $expense->id }}" class="hidden px-4 pb-4">
                                
                                <!-- Mathematical Breakdown -->
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 font-mono text-sm">
                                    
                                    <!-- Basic Information -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Given:</h6>
                                        <div class="ml-4 space-y-1">
                                            <p>Total Amount = <code class="bg-white px-2 py-1 rounded">${{ number_format($details['amount'], 2) }}</code></p>
                                            <p>Number of People = <code class="bg-white px-2 py-1 rounded">{{ count($details['normal_splits']) + 1 }}</code></p>
                                            <p>Paid By = <code class="bg-white px-2 py-1 rounded">{{ $details['paid_by'] }}</code></p>
                                        </div>
                                    </div>

                                    <!-- Step 1: Calculate per person amount -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Step 1: Calculate per person amount</h6>
                                        <div class="ml-4">
                                            <p>Per Person = Total Amount ÷ Number of People</p>
                                            <p>Per Person = <code class="bg-white px-2 py-1 rounded">${{ number_format($details['amount'], 2) }}</code> ÷ <code class="bg-white px-2 py-1 rounded">{{ count($details['normal_splits']) + 1 }}</code></p>
                                            <p>Per Person = <code class="bg-white px-2 py-1 rounded">${{ number_format($details['per_person'], 2) }}</code></p>
                                        </div>
                                    </div>

                                    <!-- Step 2: Normal split -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Step 2: Normal expense split</h6>
                                        <div class="ml-4 space-y-1">
                                            @foreach($details['normal_splits'] as $split)
                                                <p>{{ $split['user_name'] }} owes {{ $details['paid_by'] }} = <code class="bg-white px-2 py-1 rounded">${{ number_format($split['owes_amount'], 2) }}</code></p>
                                            @endforeach
                                            <p>{{ $details['paid_by'] }}'s share = <code class="bg-white px-2 py-1 rounded">${{ number_format($details['per_person'], 2) }}</code> (covered by paying)</p>
                                        </div>
                                    </div>

                                    <!-- Step 3: Debt reduction -->
                                    @if(count($details['debt_reductions']) > 0)
                                        <div class="mb-4">
                                            <h6 class="font-bold text-gray-800 mb-2">Step 3: Automatic debt reduction</h6>
                                            <div class="ml-4 space-y-3">
                                                @foreach($details['debt_reductions'] as $reduction)
                                                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                                                        <div class="flex items-center gap-2 mb-2">
                                                            <span class="text-blue-600 font-bold">🔄</span>
                                                            <span class="font-semibold text-blue-800">{{ $details['paid_by'] }} → {{ $reduction['user_name'] }} debt reduction</span>
                                                        </div>
                                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                                            <div class="space-y-1">
                                                                <p><span class="font-medium text-gray-600">Debt before:</span> <code class="bg-white px-2 py-1 rounded border">${{ number_format($reduction['debt_before'], 2) }}</code></p>
                                                                <p><span class="font-medium text-gray-600">Reduction amount:</span> <code class="bg-white px-2 py-1 rounded border">${{ number_format($reduction['reduction_amount'], 2) }}</code></p>
                                                            </div>
                                                            <div class="space-y-1">
                                                                <p><span class="font-medium text-gray-600">Calculation:</span> <code class="bg-white px-2 py-1 rounded border">${{ number_format($reduction['debt_before'], 2) }}</code> - <code class="bg-white px-2 py-1 rounded border">${{ number_format($reduction['reduction_amount'], 2) }}</code></p>
                                                                <p><span class="font-medium text-gray-600">Debt after:</span> <code class="bg-white px-2 py-1 rounded border font-bold {{ $reduction['debt_after'] > 0 ? 'text-red-600' : 'text-green-600' }}">${{ number_format($reduction['debt_after'], 2) }}</code></p>
                                                            </div>
                                                        </div>
                                                        @if($reduction['debt_after'] > 0)
                                                            <div class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded text-sm">
                                                                <span class="text-yellow-700">⚠️ Remaining debt: ${{ number_format($reduction['debt_after'], 2) }}</span>
                                                            </div>
                                                        @else
                                                            <div class="mt-2 p-2 bg-green-50 border border-green-200 rounded text-sm">
                                                                <span class="text-green-700">✅ Debt fully paid off!</span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>

                                        <!-- Step 4: Summary -->
                                        <div class="mb-4">
                                            <h6 class="font-bold text-gray-800 mb-2">Step 4: Debt reduction summary</h6>
                                            <div class="ml-4">
                                                <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <span class="text-green-600 font-bold">💰</span>
                                                        <span class="font-semibold text-green-800">Total debt reduction: ${{ number_format(array_sum(array_column($details['debt_reductions'], 'reduction_amount')), 2) }}</span>
                                                    </div>
                                                    <p class="text-sm text-green-700">
                                                        The amount others owe {{ $details['paid_by'] }} from this expense (${{ number_format($details['per_person'] * (count($details['normal_splits'])), 2) }}) was automatically used to pay down existing debts. ({{ $details['paid_by'] }}'s own share of ${{ number_format($details['per_person'], 2) }} is not included as they paid for themselves.)
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="mb-4">
                                            <h6 class="font-bold text-gray-800 mb-2">Step 3: No debt reduction</h6>
                                            <div class="ml-4">
                                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <span class="text-gray-600 font-bold">ℹ️</span>
                                                        <span class="font-semibold text-gray-800">No existing debts found</span>
                                                    </div>
                                                    <p class="text-sm text-gray-600">
                                                        {{ $details['paid_by'] }} had no existing debts to reduce. The expense will be split normally among all participants.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    <!-- Step 5: Wallet Balance Snapshot -->
                                    @if(isset($details['wallet_snapshot']) && count($details['wallet_snapshot']) > 0)
                                        <div class="mb-4">
                                            <h6 class="font-bold text-gray-800 mb-3">Step 5: Wallet balance snapshot after this transaction</h6>
                                            <div class="ml-4">
                                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                                    @foreach($details['wallet_snapshot'] as $userId => $wallet)
                                                        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 border-2 border-purple-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                                                            <!-- Wallet Header -->
                                                            <div class="flex items-center justify-between mb-3">
                                                                <div class="flex items-center gap-2">
                                                                    <span class="text-2xl">💳</span>
                                                                    <span class="font-bold text-purple-800">{{ $wallet['user_name'] }}</span>
                                                                </div>
                                                                <div class="text-right">
                                                                    <span class="text-lg font-bold px-3 py-1 rounded-full {{ $wallet['net_balance'] >= 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                                                        {{ $wallet['net_balance'] >= 0 ? '+' : '' }}${{ number_format($wallet['net_balance'], 2) }}
                                                                    </span>
                                                                    <p class="text-xs text-gray-500 mt-1">Net Balance</p>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Owes Section -->
                                                            @if(count($wallet['owes']) > 0)
                                                                <div class="mb-3">
                                                                    <div class="flex items-center gap-1 mb-2">
                                                                        <span class="text-red-500">📤</span>
                                                                        <span class="text-sm font-semibold text-red-700">Owes</span>
                                                                    </div>
                                                                    <div class="space-y-1">
                                                                        @foreach($wallet['owes'] as $otherUserId => $amount)
                                                                            <div class="bg-red-50 border border-red-200 rounded-lg p-2">
                                                                                <div class="flex justify-between items-center">
                                                                                    <span class="text-sm text-red-700">{{ $users->find($otherUserId)->name ?? 'Unknown' }}</span>
                                                                                    <span class="font-bold text-red-800">${{ number_format($amount, 2) }}</span>
                                                                                </div>
                                                                            </div>
                                                                        @endforeach
                                                                    </div>
                                                                </div>
                                                            @endif
                                                            
                                                            <!-- Receives Section -->
                                                            @if(count($wallet['receives']) > 0)
                                                                <div class="mb-3">
                                                                    <div class="flex items-center gap-1 mb-2">
                                                                        <span class="text-green-500">📥</span>
                                                                        <span class="text-sm font-semibold text-green-700">Will Receive</span>
                                                                    </div>
                                                                    <div class="space-y-1">
                                                                        @foreach($wallet['receives'] as $otherUserId => $amount)
                                                                            <div class="bg-green-50 border border-green-200 rounded-lg p-2">
                                                                                <div class="flex justify-between items-center">
                                                                                    <span class="text-sm text-green-700">{{ $users->find($otherUserId)->name ?? 'Unknown' }}</span>
                                                                                    <span class="font-bold text-green-800">${{ number_format($amount, 2) }}</span>
                                                                                </div>
                                                                            </div>
                                                                        @endforeach
                                                                    </div>
                                                                </div>
                                                            @endif
                                                            
                                                            <!-- No Debts Message -->
                                                            @if(count($wallet['owes']) == 0 && count($wallet['receives']) == 0)
                                                                <div class="text-center py-4">
                                                                    <span class="text-4xl">😊</span>
                                                                    <p class="text-sm text-gray-500 mt-2">All settled up!</p>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    @php $settlement = $transaction->data @endphp
                    <div class="bg-green-50 rounded-xl border-l-4 border-green-500 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex-1">
                                <p class="font-bold text-lg text-gray-800">
                                    {{ $settlement->fromUser->name }} paid {{ $settlement->toUser->name }}
                                </p>
                                <p class="text-gray-600">
                                    {{ $settlement->settlement_date->format('M d, Y') }}
                                    @if($settlement->payment_screenshot && file_exists(public_path('uploads/' . $settlement->payment_screenshot)))
                                        • <a href="/uploads/{{ $settlement->payment_screenshot }}" target="_blank" 
                                             class="text-green-600 hover:underline">📷 Payment Proof</a>
                                    @elseif($settlement->payment_screenshot)
                                        • <span class="text-gray-400">📷 Payment Proof (missing)</span>
                                    @endif
                                    <br><span class="text-xs text-gray-500">Recorded {{ $settlement->created_at->diffForHumans() }}</span>
                                </p>
                            </div>
                            <p class="font-bold text-xl text-green-600">${{ number_format($settlement->amount, 2) }}</p>
                        </div>

                        <!-- Settlement Breakdown -->
                        <div class="bg-white rounded-lg border border-green-200 mt-4">
                            <button onclick="toggleSettlementBreakdown({{ $settlement->id }})" 
                                    class="w-full p-4 text-left font-semibold text-gray-800 flex items-center justify-between hover:bg-gray-50 transition-colors border-b border-gray-200">
                                <div class="flex items-center gap-2">
                                    <span class="text-green-600">📊</span> Payment Breakdown
                                    <span class="text-xs text-gray-500 font-normal">(Click to expand)</span>
                                </div>
                                <span id="settlement-breakdown-icon-{{ $settlement->id }}" class="text-gray-500 transform transition-transform duration-200">▼</span>
                            </button>
                            
                            <div id="settlement-breakdown-content-{{ $settlement->id }}" class="hidden px-4 pb-4">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 font-mono text-sm">
                                    @php
                                        // Get pre-calculated settlement details
                                        $details = $settlementDetails[$settlement->id] ?? null;
                                        $currentDebt = $details['current_debt'] ?? 0;
                                        $paymentAmount = $details['payment_amount'] ?? $settlement->amount;
                                        $reduction = $details['reduction'] ?? 0;
                                        $remainingDebt = $details['remaining_debt'] ?? 0;
                                        $excessPayment = $details['excess_payment'] ?? 0;
                                    @endphp
                                    
                                    <!-- Basic Information -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Given:</h6>
                                        <div class="ml-4 space-y-1">
                                            <p>Payment Amount = <code class="bg-white px-2 py-1 rounded">${{ number_format($paymentAmount, 2) }}</code></p>
                                            <p>Paid By = <code class="bg-white px-2 py-1 rounded">{{ $settlement->fromUser->name }}</code></p>
                                            <p>Paid To = <code class="bg-white px-2 py-1 rounded">{{ $settlement->toUser->name }}</code></p>
                                            <p>Date = <code class="bg-white px-2 py-1 rounded">{{ $settlement->settlement_date->format('M d, Y') }}</code></p>
                                        </div>
                                    </div>

                                    <!-- Step 1: Debt before payment -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Step 1: Debt before payment</h6>
                                        <div class="ml-4">
                                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                                                <p>{{ $settlement->fromUser->name }} owed {{ $settlement->toUser->name }} = <code class="bg-white px-2 py-1 rounded">${{ number_format($currentDebt, 2) }}</code></p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Step 2: Payment processing -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Step 2: Payment processing</h6>
                                        <div class="ml-4 space-y-3">
                                            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="text-green-600 font-bold">💰</span>
                                                    <span class="font-semibold text-green-800">Payment Details</span>
                                                </div>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                                    <div class="space-y-1">
                                                        <p><span class="font-medium text-gray-600">Payment amount:</span> <code class="bg-white px-2 py-1 rounded border">${{ number_format($paymentAmount, 2) }}</code></p>
                                                        <p><span class="font-medium text-gray-600">Debt reduction:</span> <code class="bg-white px-2 py-1 rounded border">${{ number_format($reduction, 2) }}</code></p>
                                                    </div>
                                                    <div class="space-y-1">
                                                        <p><span class="font-medium text-gray-600">Calculation:</span> <code class="bg-white px-2 py-1 rounded border">${{ number_format($currentDebt, 2) }}</code> - <code class="bg-white px-2 py-1 rounded border">${{ number_format($reduction, 2) }}</code></p>
                                                        <p><span class="font-medium text-gray-600">Remaining debt:</span> <code class="bg-white px-2 py-1 rounded border font-bold {{ $remainingDebt > 0 ? 'text-red-600' : 'text-green-600' }}">${{ number_format($remainingDebt, 2) }}</code></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Step 3: Result -->
                                    <div class="mb-4">
                                        <h6 class="font-bold text-gray-800 mb-2">Step 3: Result after payment</h6>
                                        <div class="ml-4">
                                            @if($remainingDebt > 0)
                                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <span class="text-yellow-600 font-bold">⚠️</span>
                                                        <span class="font-semibold text-yellow-800">Partial Payment</span>
                                                    </div>
                                                    <p class="text-sm text-yellow-700">
                                                        {{ $settlement->fromUser->name }} still owes {{ $settlement->toUser->name }} <strong>${{ number_format($remainingDebt, 2) }}</strong>
                                                    </p>
                                                </div>
                                            @elseif($excessPayment > 0)
                                                <div class="bg-orange-50 border border-orange-200 rounded-lg p-3">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <span class="text-orange-600 font-bold">🔄</span>
                                                        <span class="font-semibold text-orange-800">Overpayment - Debt Reversal</span>
                                                    </div>
                                                    <p class="text-sm text-orange-700">
                                                        {{ $settlement->toUser->name }} now owes {{ $settlement->fromUser->name }} <strong>${{ number_format($excessPayment, 2) }}</strong>
                                                    </p>
                                                </div>
                                            @else
                                                <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <span class="text-green-600 font-bold">✅</span>
                                                        <span class="font-semibold text-green-800">Debt Fully Paid</span>
                                                    </div>
                                                    <p class="text-sm text-green-700">
                                                        {{ $settlement->fromUser->name }} and {{ $settlement->toUser->name }} are now settled up!
                                                    </p>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        @if($settlement->payment_screenshot && file_exists(public_path('uploads/' . $settlement->payment_screenshot)))
                            <div class="mt-3 p-3 bg-white rounded-lg border border-green-200">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-green-600">📸</span>
                                    <span class="text-sm font-medium text-gray-700">Payment Screenshot</span>
                                </div>
                                <a href="/uploads/{{ $settlement->payment_screenshot }}" target="_blank" 
                                   class="block">
                                    <img src="/uploads/{{ $settlement->payment_screenshot }}" 
                                         alt="Payment Screenshot" 
                                         class="w-full max-w-xs h-32 object-cover rounded-lg border border-gray-200 hover:opacity-80 transition-opacity"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div style="display:none;" class="w-full max-w-xs h-32 bg-gray-100 rounded-lg border border-gray-200 flex items-center justify-center text-gray-500 text-sm">
                                        Image not found
                                    </div>
                                </a>
                                <p class="text-xs text-gray-500 mt-1">Click to view full size</p>
                            </div>
                        @elseif($settlement->payment_screenshot)
                            <div class="mt-3 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-yellow-600">⚠️</span>
                                    <span class="text-sm font-medium text-gray-700">Payment Screenshot Missing</span>
                                </div>
                                <p class="text-xs text-gray-500">The payment screenshot file could not be found.</p>
                            </div>
                        @endif
                    </div>
                @endif
            @empty
                <div class="text-center py-8 text-gray-500">
                    <div class="text-4xl mb-2">📝</div>
                    <p class="text-lg">No transactions yet. Add your first expense above!</p>
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        @if($expenses->hasPages() || $settlements->hasPages())
            <div class="mt-6 flex justify-center">
                <div class="flex space-x-2">
                    @if($expenses->currentPage() > 1)
                        <a href="{{ $expenses->previousPageUrl() }}" 
                           class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                            ← Previous
                        </a>
                    @endif
                    
                    <span class="px-4 py-2 bg-blue-600 text-white rounded-lg">
                        Page {{ $expenses->currentPage() }} of {{ $expenses->lastPage() }}
                    </span>
                    
                    @if($expenses->hasMorePages())
                        <a href="{{ $expenses->nextPageUrl() }}" 
                           class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                            Next →
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing form validation...');
        
    // Calculate per-person amount
        const amountInput = document.querySelector('input[name="amount"]');
        if (amountInput) {
            amountInput.addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;
        const userCount = {{ max($users->count(), 1) }};
        const perPerson = amount / userCount;
        document.getElementById('perPerson').textContent = perPerson.toFixed(2);
    });
        }

        // Handle file selection with validation
        const receiptFile = document.getElementById('receiptFile');
        if (receiptFile) {
            receiptFile.addEventListener('change', function() {
        const fileName = this.files[0] ? this.files[0].name : 'No file selected';
        console.log('Selected file:', fileName);
                
                // Show file name and validation
                showFileStatus(this, 'receipt-status');
            });
        }

        // Handle payment screenshot file selection with validation
        const paymentScreenshotFile = document.getElementById('paymentScreenshotFile');
        if (paymentScreenshotFile) {
            paymentScreenshotFile.addEventListener('change', function() {
        const fileName = this.files[0] ? this.files[0].name : 'No file selected';
        console.log('Selected payment screenshot:', fileName);
                
                // Show file name and validation
                showFileStatus(this, 'payment-status');
            });
        }

        // Global functions for file selection
        window.selectFromGallery = function(inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.click();
            }
        };

        window.takePhoto = function(cameraInputId, targetInputId) {
            const cameraInput = document.getElementById(cameraInputId);
            const targetInput = document.getElementById(targetInputId);
            
            if (cameraInput && targetInput) {
                cameraInput.click();
                
                // When camera input changes, copy the file to target input
                cameraInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        // Create a new FileList with the camera file
                        const file = this.files[0];
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        targetInput.files = dataTransfer.files;
                        
                        // Trigger change event on target input
                        const event = new Event('change', { bubbles: true });
                        targetInput.dispatchEvent(event);
                    }
                });
            }
        };

        // Add event listener to hide main input when file is selected
        if (receiptFile) {
            receiptFile.addEventListener('change', function() {
                if (this.files.length > 0) {
                    // Hide the input after file is selected
                    this.classList.add('hidden');
                }
            });
        }

        if (paymentScreenshotFile) {
            paymentScreenshotFile.addEventListener('change', function() {
                if (this.files.length > 0) {
                    // Hide the input after file is selected
                    this.classList.add('hidden');
                }
            });
        }

        // Function to show file upload status
        function showFileStatus(fileInput, statusId) {
            let statusDiv = document.getElementById(statusId);
            if (!statusDiv) {
                statusDiv = document.createElement('div');
                statusDiv.id = statusId;
                statusDiv.className = 'text-sm mt-1';
                fileInput.parentNode.appendChild(statusDiv);
            }
            
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const isValidImage = file.type.startsWith('image/');
                const isValidSize = file.size <= 2 * 1024 * 1024; // 2MB
                
                if (isValidImage && isValidSize) {
                    statusDiv.innerHTML = `<span class="text-green-600">✓ ${file.name}</span>`;
                    statusDiv.className = 'text-sm mt-1 text-green-600';
                } else if (!isValidImage) {
                    statusDiv.innerHTML = `<span class="text-red-600">✗ Please select an image file</span>`;
                    statusDiv.className = 'text-sm mt-1 text-red-600';
                } else if (!isValidSize) {
                    statusDiv.innerHTML = `<span class="text-red-600">✗ File too large (max 2MB)</span>`;
                    statusDiv.className = 'text-sm mt-1 text-red-600';
                }
            } else {
                statusDiv.innerHTML = `<span class="text-gray-500">No file selected</span>`;
                statusDiv.className = 'text-sm mt-1 text-gray-500';
            }
        }

        // Function to show alert at top of page
        function showTopAlert(message, type = 'error') {
            // Remove any existing alerts
            const existingAlert = document.querySelector('.top-alert');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `top-alert fixed top-4 left-1/2 transform -translate-x-1/2 z-50 max-w-md w-full mx-4`;
            
            if (type === 'error') {
                alertDiv.innerHTML = `
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded shadow-lg">
                        <div class="flex items-center">
                            <span class="text-red-500 mr-2">✗</span>
                            ${message}
                        </div>
                    </div>
                `;
            } else {
                alertDiv.innerHTML = `
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded shadow-lg">
                        <div class="flex items-center">
                            <span class="text-green-500 mr-2">✓</span>
                            ${message}
                        </div>
                    </div>
                `;
            }
            
            // Add to page
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Add form validation for file uploads
        const expenseForm = document.querySelector('form[action="{{ route('expenses.store') }}"]');
        if (expenseForm) {
            expenseForm.addEventListener('submit', function(e) {
                console.log('Expense form submit triggered');
                const receiptFile = document.getElementById('receiptFile');
                
                // Always prevent default first to handle validation ourselves
                e.preventDefault();
                
                // Check if file is selected
                if (!receiptFile || !receiptFile.files.length) {
                    console.log('No receipt file selected, showing alert');
                    showTopAlert('Please upload a receipt photo before submitting the expense.');
                    
                    // Update file status to show error
                    const statusDiv = document.getElementById('receipt-status');
                    if (statusDiv) {
                        statusDiv.innerHTML = `<span class="text-red-600">✗ Please select a file</span>`;
                        statusDiv.className = 'text-sm mt-1 text-red-600';
                    }
                    
                    // Show main input and focus on it
                    if (receiptFile) {
                        receiptFile.classList.remove('hidden');
                        receiptFile.focus();
                        receiptFile.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return false;
                }
                
                // Check file validity
                const file = receiptFile.files[0];
                if (file) {
                    const isValidImage = file.type.startsWith('image/');
                    const isValidSize = file.size <= 2 * 1024 * 1024; // 2MB
                    
                    if (!isValidImage || !isValidSize) {
                        e.preventDefault();
                        let errorMessage = '';
                        if (!isValidImage) {
                            errorMessage = 'Please select a valid image file (JPG, PNG, GIF, etc.).';
                        } else if (!isValidSize) {
                            errorMessage = 'File is too large. Please select a file smaller than 2MB.';
                        }
                        showTopAlert(errorMessage);
                        
                        // Update file status to show error
                        const statusDiv = document.getElementById('receipt-status');
                        if (statusDiv) {
                            statusDiv.innerHTML = `<span class="text-red-600">✗ ${errorMessage}</span>`;
                            statusDiv.className = 'text-sm mt-1 text-red-600';
                        }
                        
                        // Show main input and focus on it
                        if (receiptFile) {
                            receiptFile.classList.remove('hidden');
                            receiptFile.focus();
                        }
                        return false;
                    }
                }
                
                console.log('Receipt file selected and valid, submitting form');
                // Submit the form manually since we prevented default
                this.submit();
            });
        }

        const settlementForm = document.querySelector('form[action="{{ route('settlements.store') }}"]');
        if (settlementForm) {
            settlementForm.addEventListener('submit', function(e) {
                console.log('Settlement form submit triggered');
                const paymentFile = document.getElementById('paymentScreenshotFile');
                
                // Always prevent default first to handle validation ourselves
                e.preventDefault();
                
                // Check if file is selected
                if (!paymentFile || !paymentFile.files.length) {
                    console.log('No payment file selected, showing alert');
                    showTopAlert('Please upload a payment screenshot before recording the settlement.');
                    
                    // Update file status to show error
                    const statusDiv = document.getElementById('payment-status');
                    if (statusDiv) {
                        statusDiv.innerHTML = `<span class="text-red-600">✗ Please select a file</span>`;
                        statusDiv.className = 'text-sm mt-1 text-red-600';
                    }
                    
                    // Show main input and focus on it
                    if (paymentFile) {
                        paymentFile.classList.remove('hidden');
                        paymentFile.focus();
                        paymentFile.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return false;
                }
                
                // Check file validity
                const file = paymentFile.files[0];
                if (file) {
                    const isValidImage = file.type.startsWith('image/');
                    const isValidSize = file.size <= 2 * 1024 * 1024; // 2MB
                    
                    if (!isValidImage || !isValidSize) {
                        e.preventDefault();
                        let errorMessage = '';
                        if (!isValidImage) {
                            errorMessage = 'Please select a valid image file (JPG, PNG, GIF, etc.).';
                        } else if (!isValidSize) {
                            errorMessage = 'File is too large. Please select a file smaller than 2MB.';
                        }
                        showTopAlert(errorMessage);
                        
                        // Update file status to show error
                        const statusDiv = document.getElementById('payment-status');
                        if (statusDiv) {
                            statusDiv.innerHTML = `<span class="text-red-600">✗ ${errorMessage}</span>`;
                            statusDiv.className = 'text-sm mt-1 text-red-600';
                        }
                        
                        // Show main input and focus on it
                        if (paymentFile) {
                            paymentFile.classList.remove('hidden');
                            paymentFile.focus();
                        }
                        return false;
                    }
                }
                
                console.log('Payment file selected and valid, submitting form');
                // Submit the form manually since we prevented default
                this.submit();
            });
        }

    // Settlement form validation
        const settlementFormForValidation = document.querySelector('form[action="{{ route('settlements.store') }}"]');
        if (settlementFormForValidation) {
            const fromUserSelect = settlementFormForValidation.querySelector('select[name="from_user_id"]');
            const toUserSelect = settlementFormForValidation.querySelector('select[name="to_user_id"]');
            const amountInput = settlementFormForValidation.querySelector('input[name="amount"]');
    
    // Store current balances for validation
    const currentBalances = @json($balances);
    
    // Function to update payment preview
    function updatePaymentPreview() {
        const fromUserId = parseInt(fromUserSelect.value);
        const toUserId = parseInt(toUserSelect.value);
        const amount = parseFloat(amountInput.value) || 0;
        const paymentPreview = document.getElementById('payment-preview');
        const previewContent = document.getElementById('preview-content');
        
        if (fromUserId && toUserId && amount > 0) {
            // Get current debt
            let currentDebt = 0;
            if (currentBalances[fromUserId] && currentBalances[fromUserId].owes && currentBalances[fromUserId].owes[toUserId]) {
                currentDebt = currentBalances[fromUserId].owes[toUserId];
            }
            
            // Get user names
            const fromUserName = currentBalances[fromUserId]?.name || 'Unknown';
            const toUserName = currentBalances[toUserId]?.name || 'Unknown';
            
            let previewHtml = '';
            
            if (currentDebt > 0) {
                // Calculate new debt after payment
                const newDebt = Math.max(0, currentDebt - amount);
                const reduction = Math.min(amount, currentDebt);
                
                previewHtml = `
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="font-medium">Current debt:</span>
                            <span class="font-bold">$${currentDebt.toFixed(2)}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="font-medium">Payment amount:</span>
                            <span class="font-bold">$${amount.toFixed(2)}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="font-medium">Debt reduction:</span>
                            <span class="font-bold text-green-600">$${reduction.toFixed(2)}</span>
                        </div>
                        <hr class="border-blue-300">
                        <div class="flex justify-between items-center">
                            <span class="font-medium">Remaining debt:</span>
                            <span class="font-bold ${newDebt > 0 ? 'text-red-600' : 'text-green-600'}">$${newDebt.toFixed(2)}</span>
                        </div>
                        ${newDebt === 0 ? '<div class="text-center mt-2"><span class="text-green-600 font-semibold">✅ Debt will be fully paid!</span></div>' : ''}
                    </div>
                `;
            } else {
                // No existing debt - will create new debt
                previewHtml = `
                    <div class="space-y-2">
                        <div class="text-center text-blue-700">
                            <p class="font-medium">${fromUserName} currently owes ${toUserName} <span class="font-bold">$0.00</span></p>
                            <p class="text-sm mt-1">This payment will create a new debt:</p>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="font-medium">${toUserName} will owe ${fromUserName}:</span>
                            <span class="font-bold text-orange-600">$${amount.toFixed(2)}</span>
                        </div>
                        <div class="text-center text-sm text-blue-600 mt-2">
                            💡 This happens when someone pays more than they owe
                        </div>
                    </div>
                `;
            }
            
            previewContent.innerHTML = previewHtml;
            paymentPreview.classList.remove('hidden');
        } else {
            paymentPreview.classList.add('hidden');
        }
    }
    
    function validateSettlementAmount() {
        const fromUserId = parseInt(fromUserSelect.value);
        const toUserId = parseInt(toUserSelect.value);
        const amount = parseFloat(amountInput.value) || 0;
        
        if (fromUserId && toUserId) {
            // Find the current debt amount from the balances structure
            let currentDebt = 0;
            if (currentBalances[fromUserId] && currentBalances[fromUserId].owes && currentBalances[fromUserId].owes[toUserId]) {
                currentDebt = currentBalances[fromUserId].owes[toUserId];
            }
            
            // Show/hide maximum amount hint
            const maxAmountHint = document.getElementById('max-amount-hint');
            const maxAmountValue = document.getElementById('max-amount-value');
            
            if (currentDebt > 0) {
                maxAmountValue.textContent = currentDebt.toFixed(2);
                maxAmountHint.classList.remove('hidden');
            } else {
                maxAmountHint.classList.add('hidden');
            }
            
            // Show validation message
            let errorDiv = document.getElementById('settlement-amount-error');
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.id = 'settlement-amount-error';
                errorDiv.className = 'text-red-500 text-sm mt-1';
                amountInput.parentNode.appendChild(errorDiv);
            }
            
            if (amount > currentDebt) {
                errorDiv.textContent = `You can only pay up to $${currentDebt.toFixed(2)} (the amount you currently owe).`;
                amountInput.setCustomValidity('Payment amount exceeds debt');
            } else {
                errorDiv.textContent = '';
                amountInput.setCustomValidity('');
            }
        } else {
            // Hide hint when no users selected
            document.getElementById('max-amount-hint').classList.add('hidden');
        }
    }
    
    // Add event listeners for validation
            if (fromUserSelect) fromUserSelect.addEventListener('change', function() {
                validateSettlementAmount();
                updatePaymentPreview();
            });
            if (toUserSelect) toUserSelect.addEventListener('change', function() {
                validateSettlementAmount();
                updatePaymentPreview();
            });
            if (amountInput) amountInput.addEventListener('input', function() {
                validateSettlementAmount();
                updatePaymentPreview();
            });
    
    // Prevent form submission if validation fails
            settlementFormForValidation.addEventListener('submit', function(e) {
        validateSettlementAmount();
                if (amountInput && !amountInput.checkValidity()) {
            e.preventDefault();
        }
    });
        }

    // Toggle breakdown visibility
        window.toggleBreakdown = function(expenseId) {
        const content = document.getElementById(`breakdown-content-${expenseId}`);
        const icon = document.getElementById(`breakdown-icon-${expenseId}`);
        
            if (content && icon) {
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden');
            icon.style.transform = 'rotate(180deg)';
        } else {
            content.classList.add('hidden');
            icon.style.transform = 'rotate(0deg)';
        }
    }
        }

        // Toggle settlement breakdown visibility
        window.toggleSettlementBreakdown = function(settlementId) {
            const content = document.getElementById(`settlement-breakdown-content-${settlementId}`);
            const icon = document.getElementById(`settlement-breakdown-icon-${settlementId}`);
            
            if (content && icon) {
                if (content.classList.contains('hidden')) {
                    content.classList.remove('hidden');
                    icon.style.transform = 'rotate(180deg)';
                } else {
                    content.classList.add('hidden');
                    icon.style.transform = 'rotate(0deg)';
                }
            }
        }
    }); // End of DOMContentLoaded
</script>
@endpush