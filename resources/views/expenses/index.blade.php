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
    <div class="max-w-4xl mx-auto py-6 px-4">
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

        <!-- Wallet Overview -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">💰 Wallet Status</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach($balances as $userId => $balance)
                    @php
                        $totalOwed = array_sum($balance['owes']);
                        $totalOwedBy = array_sum($balance['owed_by']);
                        $netAmount = $totalOwedBy - $totalOwed;
                    @endphp
                    <div class="text-center p-6 rounded-xl {{ $netAmount > 0 ? 'bg-green-50 border-2 border-green-200' : ($netAmount < 0 ? 'bg-red-50 border-2 border-red-200' : 'bg-gray-50 border-2 border-gray-200') }}">
                        <div class="text-2xl mb-2">
                            @if($netAmount > 0)
                                😊
                            @elseif($netAmount < 0)
                                😅
                            @else
                                🎉
                            @endif
                        </div>
                        <h3 class="font-bold text-xl text-gray-800 mb-3">{{ $balance['name'] }}</h3>
                        
                        @if($netAmount > 0)
                            <div class="space-y-1">
                                <p class="text-sm font-medium text-green-600">Wallet Balance:</p>
                                <p class="text-2xl font-bold text-green-600">+${{ number_format($netAmount, 2) }}</p>
                                <p class="text-xs text-gray-600">Will receive from others</p>
                            </div>
                        @elseif($netAmount < 0)
                            <div class="space-y-1">
                                <p class="text-sm font-medium text-red-600">Wallet Balance:</p>
                                <p class="text-2xl font-bold text-red-600">-${{ number_format(abs($netAmount), 2) }}</p>
                                <p class="text-xs text-gray-600">Needs to pay others</p>
                            </div>
                        @else
                            <div class="space-y-1">
                                <p class="text-lg font-bold text-gray-600">All settled up!</p>
                                <p class="text-xs text-gray-500">$0.00 balance</p>
                            </div>
                        @endif

                        @if(count($balance['owes']) > 0 || count($balance['owed_by']) > 0)
                            <div class="mt-4 pt-4 border-t border-gray-200">
                                @if(count($balance['owes']) > 0)
                                    <div class="mb-2">
                                        <p class="text-xs font-medium text-red-600 mb-1">Needs to pay:</p>
                                        @foreach($balance['owes'] as $toUserId => $amount)
                                            @php $toUser = $users->find($toUserId) @endphp
                                            <p class="text-sm text-red-600">
                                                ${{ number_format($amount, 2) }} to {{ $toUser->name }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif

                                @if(count($balance['owed_by']) > 0)
                                    <div>
                                        <p class="text-xs font-medium text-green-600 mb-1">Will receive:</p>
                                        @foreach($balance['owed_by'] as $fromUserId => $amount)
                                            @php $fromUser = $users->find($fromUserId) @endphp
                                            <p class="text-sm text-green-600">
                                                ${{ number_format($amount, 2) }} from {{ $fromUser->name }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
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

                    <!-- Payback Toggle -->
                    <div class="bg-yellow-50 border-2 border-yellow-200 rounded-xl p-4">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" name="is_payback" id="paybackToggle" value="1"
                                   class="w-5 h-5 text-yellow-600 border-2 border-yellow-300 rounded focus:ring-yellow-500">
                            <div>
                                <span class="text-lg font-medium text-yellow-800">💳 Pay back debt with this expense</span>
                                <p class="text-sm text-yellow-700">Use this expense to reduce what you owe someone</p>
                            </div>
                        </label>
                    </div>

                    <!-- Payback Options (Hidden by default) -->
                    <div id="paybackOptions" class="hidden space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pay back debt to:</label>
                            <select name="payback_to_user_id" 
                                    class="w-full text-lg border-2 border-yellow-200 rounded-xl px-4 py-3 focus:outline-none focus:border-yellow-500">
                                <option value="">Select person to pay back</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount to pay back:</label>
                            <input type="number" name="payback_amount" step="0.01" min="0.01"
                                   class="w-full text-lg border-2 border-yellow-200 rounded-xl px-4 py-3 focus:outline-none focus:border-yellow-500"
                                   placeholder="Amount to reduce from debt">
                            <p class="text-xs text-gray-500 mt-1">Leave empty to use full expense amount</p>
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
                            <input type="file" name="receipt_photo" id="receiptFile" accept="image/*" capture="environment"
                                   class="hidden">
                            <div class="flex gap-2">
                                <button type="button" onclick="document.getElementById('receiptFile').click()" 
                                        class="flex-1 bg-gray-100 text-gray-700 py-3 px-4 rounded-xl text-sm hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                                    📁 Choose File
                                </button>
                                <button type="button" onclick="document.getElementById('receiptFile').click()" 
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
                
                <form action="{{ route('settlements.store') }}" method="POST" class="space-y-4">
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
                        <input type="number" name="amount" step="0.01" min="0.01" required 
                               class="flex-1 text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500"
                               placeholder="Amount paid back">
                        <input type="date" name="settlement_date" required 
                               value="{{ date('Y-m-d') }}"
                               class="flex-1 text-lg border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:border-green-500">
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
                        @php $expense = $transaction->data @endphp
                        <div class="flex items-center justify-between p-4 {{ $expense->is_payback ? 'bg-yellow-50 border-l-4 border-yellow-500' : 'bg-blue-50 border-l-4 border-blue-500' }} rounded-xl">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <p class="font-bold text-lg text-gray-800">{{ $expense->description }}</p>
                                    @if($expense->is_payback)
                                        <span class="bg-yellow-200 text-yellow-800 text-xs font-bold px-2 py-1 rounded-full">💳 PAYBACK</span>
                                    @endif
                                </div>
                                <p class="text-gray-600">
                                    Paid by {{ $expense->paidByUser->name }} • {{ $expense->expense_date->format('M d, Y') }}
                                    @if($expense->is_payback && $expense->paybackToUser)
                                        • <span class="text-yellow-700 font-medium">Paid back ${{ number_format($expense->payback_amount, 2) }} to {{ $expense->paybackToUser->name }}</span>
                                    @endif
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
                    @else
                        @php $settlement = $transaction->data @endphp
                        <div class="flex items-center justify-between p-4 bg-green-50 rounded-xl border-l-4 border-green-500">
                            <div class="flex-1">
                                <p class="font-bold text-lg text-gray-800">
                                    {{ $settlement->fromUser->name }} paid {{ $settlement->toUser->name }}
                                </p>
                                <p class="text-gray-600">
                                    {{ $settlement->settlement_date->format('M d, Y') }}
                                    <br><span class="text-xs text-gray-500">Recorded {{ $settlement->created_at->diffForHumans() }}</span>
                                </p>
                            </div>
                            <p class="font-bold text-xl text-green-600">${{ number_format($settlement->amount, 2) }}</p>
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

        // Toggle payback options
        document.getElementById('paybackToggle').addEventListener('change', function() {
            const paybackOptions = document.getElementById('paybackOptions');
            if (this.checked) {
                paybackOptions.classList.remove('hidden');
            } else {
                paybackOptions.classList.add('hidden');
            }
        });

        // Handle file selection
        document.getElementById('receiptFile').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            console.log('Selected file:', fileName);
        });
    </script>
</body>
</html>