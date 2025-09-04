<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SplitMate - Expense Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto py-8 px-4">
        <div class="mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-2">SplitMate</h1>
            <p class="text-gray-600">Track shared expenses between Navjot, Sapna, and Anu</p>
        </div>

        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Current Balances -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">Current Balances</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @foreach($balances as $userId => $balance)
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="font-semibold text-lg text-gray-900 mb-2">{{ $balance['name'] }}</h3>
                                
                                @if(count($balance['owes']) > 0)
                                    <div class="mb-2">
                                        <p class="text-sm font-medium text-red-600 mb-1">Owes:</p>
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
                                        <p class="text-sm font-medium text-green-600 mb-1">Owed by:</p>
                                        @foreach($balance['owed_by'] as $fromUserId => $amount)
                                            @php $fromUser = $users->find($fromUserId) @endphp
                                            <p class="text-sm text-green-600">
                                                ${{ number_format($amount, 2) }} from {{ $fromUser->name }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif

                                @if(count($balance['owes']) == 0 && count($balance['owed_by']) == 0)
                                    <p class="text-sm text-gray-500">All settled up! 🎉</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">Recent Transactions</h2>
                    
                    <!-- Expenses -->
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-3">Expenses</h3>
                        <div class="space-y-3">
                            @forelse($expenses->take(5) as $expense)
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">{{ $expense->description }}</p>
                                        <p class="text-sm text-gray-600">
                                            Paid by {{ $expense->paidByUser->name }} on {{ $expense->expense_date->format('M d, Y') }}
                                        </p>
                                        @if($expense->receipt_photo)
                                            <a href="{{ Storage::url($expense->receipt_photo) }}" target="_blank" 
                                               class="text-xs text-blue-600 hover:underline">View Receipt</a>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-lg text-gray-900">${{ number_format($expense->amount, 2) }}</p>
                                        <p class="text-sm text-gray-600">${{ number_format($expense->amount_per_person, 2) }} each</p>
                                    </div>
                                </div>
                            @empty
                                <p class="text-gray-500 italic">No expenses recorded yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <!-- Settlements -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-3">Settlements</h3>
                        <div class="space-y-3">
                            @forelse($settlements->take(5) as $settlement)
                                <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                    <div>
                                        <p class="font-medium text-gray-900">
                                            {{ $settlement->fromUser->name }} → {{ $settlement->toUser->name }}
                                        </p>
                                        <p class="text-sm text-gray-600">{{ $settlement->settlement_date->format('M d, Y') }}</p>
                                    </div>
                                    <p class="font-semibold text-green-700">${{ number_format($settlement->amount, 2) }}</p>
                                </div>
                            @empty
                                <p class="text-gray-500 italic">No settlements recorded yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Forms -->
            <div class="space-y-6">
                <!-- Add Expense Form -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Add Expense</h2>
                    <form action="{{ route('expenses.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="description" required 
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="Grocery shopping">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($)</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required 
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="100.00">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Paid by</label>
                            <select name="paid_by_user_id" required 
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select person</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="date" name="expense_date" required 
                                   value="{{ date('Y-m-d') }}"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Photo</label>
                            <input type="file" name="receipt_photo" accept="image/*"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Upload bill receipt for proof</p>
                        </div>

                        <button type="submit" 
                                class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            Add Expense
                        </button>
                    </form>
                </div>

                <!-- Record Settlement Form -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Record Settlement</h2>
                    <form action="{{ route('settlements.store') }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
                            <select name="from_user_id" required 
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">Who paid?</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
                            <select name="to_user_id" required 
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">Who received?</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($)</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required 
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500"
                                   placeholder="33.33">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Settlement Date</label>
                            <input type="date" name="settlement_date" required 
                                   value="{{ date('Y-m-d') }}"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>

                        <button type="submit" 
                                class="w-full bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                            Record Settlement
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>