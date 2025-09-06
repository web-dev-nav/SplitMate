<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SplitMate - Simple Expense Splitter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        success: '#10B981',
                        warning: '#F59E0B',
                        danger: '#EF4444'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-6 px-4 overflow-x-hidden">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="flex justify-between items-center mb-4">
                <div></div>
                <h1 class="text-5xl font-bold text-gray-800">💰 SplitMate</h1>
                <a href="{{ route('settings.index') }}" 
                   class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200 transition-colors">
                    ⚙️ Settings
                </a>
            </div>
            <p class="text-gray-600 text-lg">Simple expense splitting among {{ $users->count() }} people</p>
        </div>

        @if(session('success'))
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded mb-6 shadow-sm">
                <div class="flex items-center">
                    <span class="text-green-500 mr-2">✓</span>
                    {{ session('success') }}
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded mb-6 shadow-sm">
                <div class="flex items-center">
                    <span class="text-red-500 mr-2">✗</span>
                    {{ session('error') }}
                </div>
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded mb-6 shadow-sm">
                <div class="flex items-center">
                    <span class="text-red-500 mr-2">✗</span>
                    <div>
                        <strong>Validation errors:</strong>
                        <ul class="list-disc list-inside mt-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <!-- Wallet Overview - Updated for mobile responsiveness -->
        <div class="bg-white rounded-2xl shadow-lg p-4 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">💰 Wallet Status</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4" style="width: 100%; max-width: 100%; overflow-x: hidden;">
                @if(count($balances) > 0)
                    @foreach($balances as $userId => $balance)
                    @php
                        $totalOwed = array_sum($balance['owes']);
                        $totalOwedBy = array_sum($balance['owed_by']);
                        $netAmount = $totalOwedBy - $totalOwed;
                    @endphp
                    <div class="text-center p-3 rounded-xl {{ $netAmount > 0 ? 'bg-green-50 border-2 border-green-200' : ($netAmount < 0 ? 'bg-red-50 border-2 border-red-200' : 'bg-gray-50 border-2 border-gray-200') }}" style="word-wrap: break-word; overflow-wrap: break-word; max-width: 100%;">
                        <div class="text-lg md:text-2xl mb-1 md:mb-2">
                            @if($netAmount > 0)
                                😊
                            @elseif($netAmount < 0)
                                😅
                            @else
                                🎉
                            @endif
                        </div>
                        <h3 class="font-bold text-sm text-gray-800 mb-2 truncate">{{ $balance['name'] }}</h3>
                        
                        @if($netAmount > 0)
                            <div class="space-y-0.5">
                                <p class="text-xs font-medium text-green-600">Balance:</p>
                                <p class="text-base font-bold text-green-600">+${{ number_format($netAmount, 2) }}</p>
                                <p class="text-xs text-gray-600">Will receive</p>
                            </div>
                        @elseif($netAmount < 0)
                            <div class="space-y-0.5">
                                <p class="text-xs font-medium text-red-600">Balance:</p>
                                <p class="text-base font-bold text-red-600">-${{ number_format(abs($netAmount), 2) }}</p>
                                <p class="text-xs text-gray-600">Needs to pay</p>
                            </div>
                        @else
                            <div class="space-y-0.5">
                                <p class="text-sm font-bold text-gray-600">All settled!</p>
                                <p class="text-xs text-gray-500">$0.00</p>
                            </div>
                        @endif

                        @if(count($balance['owes']) > 0 || count($balance['owed_by']) > 0)
                            <div class="mt-2 pt-2 border-t border-gray-200 text-left">
                                @if(count($balance['owes']) > 0)
                                    <div class="mb-1">
                                        <p class="text-xs font-medium text-red-600 mb-1">Owes:</p>
                                        @foreach($balance['owes'] as $toUserId => $amount)
                                            @php $toUser = $users->find($toUserId) @endphp
                                            <p class="text-xs text-red-600 truncate">
                                                ${{ number_format($amount, 2) }} → {{ $toUser->name }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif

                                @if(count($balance['owed_by']) > 0)
                                    <div>
                                        <p class="text-xs font-medium text-green-600 mb-1">Gets:</p>
                                        @foreach($balance['owed_by'] as $fromUserId => $amount)
                                            @php $fromUser = $users->find($fromUserId) @endphp
                                            <p class="text-xs text-green-600 truncate">
                                                ${{ number_format($amount, 2) }} ← {{ $fromUser->name }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
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
                    <p class="text-gray-600">Split a bill between all 3 of you</p>
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
                            ÷ {{ $users->count() }} = $<span id="perPerson">0.00</span> each
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


                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="date" name="expense_date" required 
                                   value="{{ date('Y-m-d') }}"
                                   class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Photo</label>
                            <!-- Hidden file input for gallery -->
                            <input type="file" name="receipt_photo" id="receiptFileGallery" accept="image/*" 
                                   class="hidden">
                            <!-- Hidden file input for camera -->
                            <input type="file" name="receipt_photo" id="receiptFileCamera" accept="image/*" capture="environment" 
                                   class="hidden">
                            <div class="flex gap-2">
                                <button type="button" onclick="document.getElementById('receiptFileGallery').click()" 
                                        class="flex-1 bg-gray-100 text-gray-700 py-3 px-4 rounded-xl text-sm hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                                    📁 Choose File
                                </button>
                                <button type="button" onclick="document.getElementById('receiptFileCamera').click()" 
                                        class="flex-1 bg-blue-100 text-blue-700 py-3 px-4 rounded-xl text-sm hover:bg-blue-200 transition-colors flex items-center justify-center gap-2">
                                    📷 Take Photo
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Upload receipt or take a photo for proof</p>
                        </div>
                    </div>

                    <button type="submit" 
                            class="w-full bg-blue-600 text-white text-lg font-bold py-4 px-6 rounded-xl hover:bg-blue-700 transition-colors">
                        💸 Add Expense
                    </button>
                </form>
            </div>

            <!-- Record Settlement Card -->
            <div class="bg-white rounded-2xl shadow-lg p-6">
                <div class="text-center mb-6">
                    <div class="text-4xl mb-2">💚</div>
                    <h2 class="text-2xl font-bold text-gray-800">Record Payment</h2>
                    <p class="text-gray-600">Someone paid someone back</p>
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
                        <div class="flex-1">
                            <input type="number" name="amount" step="0.01" min="0.01" required 
                                   class="w-full text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500"
                                   placeholder="Amount paid back">
                            <div id="max-amount-hint" class="text-xs text-gray-500 mt-1 hidden">
                                Maximum: $<span id="max-amount-value">0.00</span>
                            </div>
                        </div>
                        <input type="date" name="settlement_date" required 
                               value="{{ date('Y-m-d') }}"
                               class="flex-1 text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500">
                    </div>

                    <!-- Payment Screenshot Upload -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Screenshot (Optional)</label>
                        <!-- Hidden file input for gallery -->
                        <input type="file" name="payment_screenshot" id="paymentScreenshotFileGallery" accept="image/*" 
                               class="hidden">
                        <!-- Hidden file input for camera -->
                        <input type="file" name="payment_screenshot" id="paymentScreenshotFileCamera" accept="image/*" capture="environment" 
                               class="hidden">
                        <div class="flex gap-2">
                            <button type="button" onclick="document.getElementById('paymentScreenshotFileGallery').click()" 
                                    class="flex-1 bg-gray-100 text-gray-700 py-3 px-4 rounded-xl text-sm hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                                📁 Choose File
                            </button>
                            <button type="button" onclick="document.getElementById('paymentScreenshotFileCamera').click()" 
                                    class="flex-1 bg-green-100 text-green-700 py-3 px-4 rounded-xl text-sm hover:bg-green-200 transition-colors flex items-center justify-center gap-2">
                                📷 Take Photo
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Upload payment proof (bank transfer, UPI screenshot, etc.)</p>
                    </div>

                    <button type="submit" 
                            class="w-full bg-green-600 text-white text-lg font-bold py-4 px-6 rounded-xl hover:bg-green-700 transition-colors">
                        💚 Record Payment
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
                                        @if($expense->receipt_photo)
                                            • <a href="{{ Storage::url($expense->receipt_photo) }}" target="_blank" 
                                                 class="text-blue-600 hover:underline">📷 Receipt</a>
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
                                    
                                    <!-- Step 1: Normal splitting -->
                                    <div class="mb-4">
                                        <h5 class="font-medium text-gray-700 mb-2 flex items-center gap-2">
                                            <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded-full">1</span>
                                            Normal Expense Split
                                        </h5>
                                        <div class="ml-6 space-y-1">
                                            @foreach($details['normal_splits'] as $split)
                                                <p class="text-sm text-gray-600">
                                                    <span class="font-medium">{{ $split['user_name'] }}</span> owes 
                                                    <span class="font-bold text-blue-600">${{ number_format($split['owes_amount'], 2) }}</span> to {{ $details['paid_by'] }}
                                                </p>
                                            @endforeach
                                        </div>
                                    </div>

                                    <!-- Step 2: Debt reduction (if any) -->
                                    @if(count($details['debt_reductions']) > 0)
                                        <div class="mb-4">
                                            <h5 class="font-medium text-gray-700 mb-2 flex items-center gap-2">
                                                <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded-full">2</span>
                                                Automatic Debt Reduction
                                            </h5>
                                            <div class="ml-6 space-y-2">
                                                <p class="text-sm text-gray-600 mb-2">
                                                    <span class="font-medium">{{ $details['paid_by'] }}'s share</span> 
                                                    (<span class="font-bold text-green-600">${{ number_format($details['per_person'], 2) }}</span>) 
                                                    automatically reduces existing debts:
                                                </p>
                                                @foreach($details['debt_reductions'] as $reduction)
                                                    <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                                        <p class="text-sm text-gray-700">
                                                            <span class="font-medium">{{ $details['paid_by'] }}</span> owed 
                                                            <span class="font-bold text-red-600">${{ number_format($reduction['debt_before'], 2) }}</span> to 
                                                            <span class="font-medium">{{ $reduction['user_name'] }}</span>
                                                        </p>
                                                        <p class="text-sm text-green-700 mt-1">
                                                            → Reduced by <span class="font-bold">${{ number_format($reduction['reduction_amount'], 2) }}</span>
                                                            → Now owes <span class="font-bold text-orange-600">${{ number_format($reduction['debt_after'], 2) }}</span>
                                                        </p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                        <div class="mb-4">
                                            <h5 class="font-medium text-gray-700 mb-2 flex items-center gap-2">
                                                <span class="bg-gray-100 text-gray-600 text-xs font-bold px-2 py-1 rounded-full">2</span>
                                                No Debt Reduction
                                            </h5>
                                            <div class="ml-6">
                                                <p class="text-sm text-gray-500">
                                                    {{ $details['paid_by'] }} had no existing debts, so no automatic reduction occurred.
                                                </p>
                                            </div>
                                        </div>
                                    @endif

                                    <!-- Summary -->
                                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                                        <h5 class="font-medium text-gray-700 mb-2 flex items-center gap-2">
                                            <span class="text-gray-600">📋</span> Summary
                                        </h5>
                                        <div class="ml-6 space-y-1">
                                            <p class="text-sm text-gray-600">
                                                • Total expense: <span class="font-bold">${{ number_format($details['amount'], 2) }}</span>
                                            </p>
                                            <p class="text-sm text-gray-600">
                                                • Split among {{ count($details['normal_splits']) + 1 }} people: <span class="font-bold">${{ number_format($details['per_person'], 2) }}</span> each
                                            </p>
                                            @if(count($details['debt_reductions']) > 0)
                                                <p class="text-sm text-gray-600">
                                                    • {{ $details['paid_by'] }}'s share reduced debts by: <span class="font-bold text-green-600">${{ number_format($details['per_person'], 2) }}</span>
                                                </p>
                                            @endif
                                        </div>
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
                                        @if($settlement->payment_screenshot)
                                            • <a href="{{ Storage::url($settlement->payment_screenshot) }}" target="_blank" 
                                                 class="text-green-600 hover:underline">📷 Payment Proof</a>
                                        @endif
                                        <br><span class="text-xs text-gray-500">Recorded {{ $settlement->created_at->diffForHumans() }}</span>
                                    </p>
                                </div>
                                <p class="font-bold text-xl text-green-600">${{ number_format($settlement->amount, 2) }}</p>
                            </div>
                            
                            @if($settlement->payment_screenshot)
                                <div class="mt-3 p-3 bg-white rounded-lg border border-green-200">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-green-600">📸</span>
                                        <span class="text-sm font-medium text-gray-700">Payment Screenshot</span>
                                    </div>
                                    <a href="{{ Storage::url($settlement->payment_screenshot) }}" target="_blank" 
                                       class="block">
                                        <img src="{{ Storage::url($settlement->payment_screenshot) }}" 
                                             alt="Payment Screenshot" 
                                             class="w-full max-w-xs h-32 object-cover rounded-lg border border-gray-200 hover:opacity-80 transition-opacity">
                                    </a>
                                    <p class="text-xs text-gray-500 mt-1">Click to view full size</p>
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
    </div>

    <script>
        // Auto-calculate per person amount
        document.querySelector('input[name="amount"]').addEventListener('input', function() {
            const amount = parseFloat(this.value) || 0;
            const userCount = {{ $users->count() }};
            const perPerson = (amount / userCount).toFixed(2);
            document.getElementById('perPerson').textContent = perPerson;
        });

        // Handle receipt file selection (both gallery and camera)
        document.getElementById('receiptFileGallery').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            console.log('Selected receipt file from gallery:', fileName);
            // Copy the file to the camera input as well
            if (this.files[0]) {
                const cameraInput = document.getElementById('receiptFileCamera');
                cameraInput.files = this.files;
            }
        });

        document.getElementById('receiptFileCamera').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            console.log('Selected receipt file from camera:', fileName);
            // Copy the file to the gallery input as well
            if (this.files[0]) {
                const galleryInput = document.getElementById('receiptFileGallery');
                galleryInput.files = this.files;
            }
        });

        // Handle payment screenshot file selection (both gallery and camera)
        document.getElementById('paymentScreenshotFileGallery').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            console.log('Selected payment screenshot from gallery:', fileName);
            // Copy the file to the camera input as well
            if (this.files[0]) {
                const cameraInput = document.getElementById('paymentScreenshotFileCamera');
                cameraInput.files = this.files;
            }
        });

        document.getElementById('paymentScreenshotFileCamera').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            console.log('Selected payment screenshot from camera:', fileName);
            // Copy the file to the gallery input as well
            if (this.files[0]) {
                const galleryInput = document.getElementById('paymentScreenshotFileGallery');
                galleryInput.files = this.files;
            }
        });

        // Settlement form validation
        const settlementForm = document.querySelector('form[action="{{ route('settlements.store') }}"]');
        const fromUserSelect = settlementForm.querySelector('select[name="from_user_id"]');
        const toUserSelect = settlementForm.querySelector('select[name="to_user_id"]');
        const amountInput = settlementForm.querySelector('input[name="amount"]');
        
        // Store current balances for validation
        const currentBalances = @json($balances);
        
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
        fromUserSelect.addEventListener('change', validateSettlementAmount);
        toUserSelect.addEventListener('change', validateSettlementAmount);
        amountInput.addEventListener('input', validateSettlementAmount);
        
        // Prevent form submission if validation fails
        settlementForm.addEventListener('submit', function(e) {
            validateSettlementAmount();
            if (!amountInput.checkValidity()) {
                e.preventDefault();
            }
        });

        // Toggle breakdown visibility
        function toggleBreakdown(expenseId) {
            const content = document.getElementById(`breakdown-content-${expenseId}`);
            const icon = document.getElementById(`breakdown-icon-${expenseId}`);
            
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                icon.style.transform = 'rotate(180deg)';
            } else {
                content.classList.add('hidden');
                icon.style.transform = 'rotate(0deg)';
            }
        }
    </script>
</body>
</html>