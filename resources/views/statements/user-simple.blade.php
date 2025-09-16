@extends('layouts.app')

@section('title', $user->name . ' - Account Statement')
@section('subtitle', 'Simple transaction history')

@section('content')
    <div class="max-w-4xl mx-auto">
        <!-- User Navigation -->
        <div class="bg-white rounded-xl shadow-sm mb-6 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold text-gray-800">Account Statement</h2>
                <a href="{{ route('expenses.index') }}"
                   class="text-sm text-blue-600 hover:text-blue-800">← Back to Dashboard</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($allUsers as $navUser)
                    <a href="{{ route('statements.user', $navUser->id) }}"
                       class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $navUser->id == $user->id ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                        {{ $navUser->name }}
                    </a>
                @endforeach
            </div>
        </div>

        <!-- Success/Error Messages -->
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
                <div class="flex items-center">
                    <span class="text-green-600 text-xl mr-3">✅</span>
                    <span class="text-green-800">{{ session('success') }}</span>
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
                <div class="flex items-center">
                    <span class="text-red-600 text-xl mr-3">❌</span>
                    <span class="text-red-800">{{ session('error') }}</span>
                </div>
            </div>
        @endif

        <!-- Account Summary -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold text-gray-800">{{ $user->name }}'s Account</h1>
                <div class="mt-4">
                    <div class="text-3xl font-bold {{ $currentBalance >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        {{ $currentBalance >= 0 ? '+' : '' }}${{ number_format($currentBalance, 2) }}
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Current Balance</div>
                    @if($currentBalance > 0)
                        <div class="text-sm text-green-600 mt-2">💰 You are owed money</div>
                    @elseif($currentBalance < 0)
                        <div class="text-sm text-red-600 mt-2">📤 You owe money</div>
                    @else
                        <div class="text-sm text-gray-600 mt-2">✅ All settled up!</div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="bg-gray-50 px-6 py-4 border-b">
                <h2 class="text-xl font-bold text-gray-800">Transaction History</h2>
                <p class="text-sm text-gray-600">{{ $statements->count() }} transactions</p>
            </div>

            @if($statements->count() > 0)
                <div class="divide-y divide-gray-200">
                    @foreach($statements as $statement)
                        <div class="p-6 hover:bg-gray-50 transition-colors">
                            <div class="flex items-start justify-between">
                                <!-- Left: Description and Date -->
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <!-- Transaction Icon -->
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center
                                            {{ $statement->transaction_type == 'expense' ? 'bg-blue-100' : 'bg-green-100' }}">
                                            <span class="text-lg">
                                                {{ $statement->transaction_type == 'expense' ? '🛒' : '💰' }}
                                            </span>
                                        </div>

                                        <!-- Description -->
                                        <div>
                                            <div class="font-medium text-gray-800">
                                                {{ $statement->description }}
                                            </div>

                                            <!-- Transaction note -->
                                            @if($statement->transaction_details && isset($statement->transaction_details['note']))
                                                <div class="text-sm text-gray-600 mt-1">
                                                    {{ $statement->transaction_details['note'] }}
                                                </div>
                                            @elseif(!$statement->transaction_details)
                                                <!-- Fallback for old records without detailed transaction_details -->
                                                <div class="text-sm text-gray-600 mt-1">
                                                    @if($statement->transaction_type == 'expense')
                                                        @if($statement->amount > 0)
                                                            💸 You paid for this expense
                                                        @else
                                                            🛒 Your share of this expense
                                                        @endif
                                                    @else
                                                        @if($statement->amount > 0)
                                                            💸 Payment received
                                                        @else
                                                            💰 Payment sent
                                                        @endif
                                                    @endif
                                                </div>
                                                <div class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded text-xs text-yellow-700">
                                                    ℹ️ This is an old record. <a href="{{ route('statements.regenerate') }}" class="underline text-blue-600 hover:text-blue-800">Click here to update all statements</a> for better details.
                                                </div>
                                            @endif

                                            <!-- Debt details - WHO OWES WHOM -->
                                            @if($statement->transaction_details && isset($statement->transaction_details['debt_details']) && is_array($statement->transaction_details['debt_details']) && count($statement->transaction_details['debt_details']) > 0)
                                                <div class="mt-2 space-y-1">
                                                    @foreach($statement->transaction_details['debt_details'] as $detail)
                                                        <div class="text-sm {{ str_contains($detail, 'reduced') ? 'text-green-600' : (str_contains($detail, 'owes you') ? 'text-green-600' : 'text-orange-600') }}">
                                                            💳 {!! $detail !!}
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="text-xs text-gray-500 mt-1">
                                                {{ $statement->transaction_date->format('M j, Y \a\t g:i A') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Amount -->
                                <div class="text-right ml-4">
                                    <div class="text-lg font-bold
                                        {{ $statement->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $statement->amount >= 0 ? '+' : '' }}${{ number_format(abs($statement->amount), 2) }}
                                    </div>

                                    <!-- Impact indicator -->
                                    <div class="text-xs {{ $statement->amount >= 0 ? 'text-green-600' : 'text-red-600' }} mt-1">
                                        @if($statement->amount > 0)
                                            💰 Money in
                                        @elseif($statement->amount < 0)
                                            📤 Money out
                                        @else
                                            ⚖️ No change
                                        @endif
                                    </div>

                                    <!-- Transaction type badge -->
                                    <div class="mt-1">
                                        <span class="px-2 py-1 text-xs rounded-full
                                            {{ $statement->transaction_type == 'expense' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">
                                            {{ ucfirst($statement->transaction_type) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Expandable Details -->
                            @if($statement->transaction_details && is_array($statement->transaction_details))
                                <div class="mt-4 pt-4 border-t border-gray-100">
                                    <details class="group transaction-details" data-statement-id="{{ $statement->id }}">
                                        <summary class="cursor-pointer text-sm text-blue-600 hover:text-blue-800 flex items-center">
                                            <span>🧮 How This Amount Was Calculated</span>
                                            <svg class="w-4 h-4 ml-1 transform group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                        </summary>
                                        <div class="mt-3 bg-gray-50 rounded-lg p-4 text-sm space-y-4">
                                            @if($statement->transaction_type == 'expense')
                                                <!-- Amount Calculation Breakdown -->
                                                <div class="bg-white rounded-lg p-3 border-l-4 border-blue-500">
                                                    <div class="font-medium text-gray-800 mb-2">💰 Amount Calculation</div>
                                                    @if(isset($statement->transaction_details['is_payer']) && $statement->transaction_details['is_payer'])
                                                        <div class="space-y-1 text-gray-700">
                                                            <div class="flex justify-between">
                                                                <span>You paid total expense:</span>
                                                                <span class="font-mono">+${{ number_format($statement->transaction_details['expense_total'] ?? $statement->amount, 2) }}</span>
                                                            </div>
                                                            @if(isset($statement->transaction_details['participants']))
                                                                <div class="text-xs text-gray-600 mt-2">
                                                                    Split among {{ $statement->transaction_details['participants'] }} people = ${{ number_format(($statement->transaction_details['expense_total'] ?? $statement->amount) / $statement->transaction_details['participants'], 2) }} each
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <div class="space-y-1 text-gray-700">
                                                            @if(isset($statement->transaction_details['expense_total']) && isset($statement->transaction_details['participants']))
                                                                <div class="flex justify-between">
                                                                    <span>Total expense:</span>
                                                                    <span class="font-mono">${{ number_format($statement->transaction_details['expense_total'], 2) }}</span>
                                                                </div>
                                                                <div class="flex justify-between">
                                                                    <span>÷ {{ $statement->transaction_details['participants'] }} people:</span>
                                                                    <span class="font-mono">${{ number_format($statement->transaction_details['your_share'], 2) }}</span>
                                                                </div>
                                                                <div class="border-t border-gray-300 pt-1 mt-2">
                                                                    <div class="flex justify-between font-medium">
                                                                        <span>Net balance impact:</span>
                                                                        <span class="font-mono {{ $statement->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                            {{ $statement->amount >= 0 ? '+' : '' }}${{ number_format(abs($statement->amount), 2) }}
                                                                        </span>
                                                                    </div>
                                                                    @if(abs($statement->amount) != $statement->transaction_details['your_share'])
                                                                        <div class="text-xs text-gray-500 mt-1">
                                                                            ℹ️ Different from your share due to debt reduction effects
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                            @else
                                                                <div class="flex justify-between">
                                                                    <span>Your share of expense:</span>
                                                                    <span class="font-mono {{ $statement->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                        {{ $statement->amount >= 0 ? '+' : '' }}${{ number_format(abs($statement->amount), 2) }}
                                                                    </span>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>

                                                <!-- Balance Impact -->
                                                <div class="bg-white rounded-lg p-3 border-l-4 border-purple-500">
                                                    <div class="font-medium text-gray-800 mb-2">📊 Balance Impact</div>
                                                    <div class="space-y-1 text-gray-700">
                                                        <div class="flex justify-between">
                                                            <span>Balance before:</span>
                                                            <span class="font-mono {{ $statement->balance_before >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ $statement->balance_before >= 0 ? '+' : '' }}${{ number_format($statement->balance_before, 2) }}
                                                            </span>
                                                        </div>
                                                        <div class="flex justify-between">
                                                            <span>This transaction:</span>
                                                            <span class="font-mono {{ $statement->balance_change >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ $statement->balance_change >= 0 ? '+' : '' }}${{ number_format($statement->balance_change, 2) }}
                                                            </span>
                                                        </div>
                                                        <div class="border-t border-gray-300 pt-1 mt-2">
                                                            <div class="flex justify-between font-medium">
                                                                <span>Balance after:</span>
                                                                <span class="font-mono {{ $statement->balance_after >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                    {{ $statement->balance_after >= 0 ? '+' : '' }}${{ number_format($statement->balance_after, 2) }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                @if(isset($statement->transaction_details['debt_details']) && count($statement->transaction_details['debt_details']) > 0)
                                                    <div>
                                                        <div class="font-medium text-gray-700 mb-2">💳 Debt Changes</div>
                                                        <div class="space-y-1">
                                                            @foreach($statement->transaction_details['debt_details'] as $detail)
                                                                <div class="flex items-center space-x-2">
                                                                    <span class="w-2 h-2 rounded-full {{ str_contains($detail, 'reduced') || str_contains($detail, 'owes you') ? 'bg-green-500' : 'bg-orange-500' }}"></span>
                                                                    <span class="text-gray-600">{!! $detail !!}</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                            @elseif($statement->transaction_type == 'settlement')
                                                <!-- Payment Calculation Breakdown -->
                                                <div class="bg-white rounded-lg p-3 border-l-4 border-green-500">
                                                    <div class="font-medium text-gray-800 mb-2">💰 Payment Calculation</div>
                                                    <div class="space-y-1 text-gray-700">
                                                        @if(isset($statement->transaction_details['payment_amount']))
                                                            <div class="flex justify-between">
                                                                <span>Payment amount:</span>
                                                                <span class="font-mono">${{ number_format($statement->transaction_details['payment_amount'], 2) }}</span>
                                                            </div>
                                                        @endif
                                                        @if(isset($statement->transaction_details['from_user']) && isset($statement->transaction_details['to_user']))
                                                            <div class="flex justify-between text-sm">
                                                                <span>Direction:</span>
                                                                <span>{{ $statement->transaction_details['from_user'] }} → {{ $statement->transaction_details['to_user'] }}</span>
                                                            </div>
                                                        @endif
                                                        <div class="border-t border-gray-300 pt-1 mt-2">
                                                            <div class="flex justify-between font-medium">
                                                                <span>Net balance impact:</span>
                                                                <span class="font-mono {{ $statement->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                    {{ $statement->amount >= 0 ? '+' : '' }}${{ number_format(abs($statement->amount), 2) }}
                                                                    @if($statement->amount > 0)
                                                                        (received)
                                                                    @else
                                                                        (sent)
                                                                    @endif
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Balance Impact -->
                                                <div class="bg-white rounded-lg p-3 border-l-4 border-purple-500">
                                                    <div class="font-medium text-gray-800 mb-2">📊 Balance Impact</div>
                                                    <div class="space-y-1 text-gray-700">
                                                        <div class="flex justify-between">
                                                            <span>Balance before:</span>
                                                            <span class="font-mono {{ $statement->balance_before >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ $statement->balance_before >= 0 ? '+' : '' }}${{ number_format($statement->balance_before, 2) }}
                                                            </span>
                                                        </div>
                                                        <div class="flex justify-between">
                                                            <span>This payment:</span>
                                                            <span class="font-mono {{ $statement->balance_change >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ $statement->balance_change >= 0 ? '+' : '' }}${{ number_format($statement->balance_change, 2) }}
                                                            </span>
                                                        </div>
                                                        <div class="border-t border-gray-300 pt-1 mt-2">
                                                            <div class="flex justify-between font-medium">
                                                                <span>Balance after:</span>
                                                                <span class="font-mono {{ $statement->balance_after >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                    {{ $statement->balance_after >= 0 ? '+' : '' }}${{ number_format($statement->balance_after, 2) }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif

                                            <div class="pt-2 border-t border-gray-200">
                                                <div class="text-xs text-gray-500">
                                                    Reference: {{ $statement->reference_number }} •
                                                    {{ $statement->transaction_date->format('M j, Y \a\t g:i A') }}
                                                </div>
                                            </div>
                                        </div>
                                    </details>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                @if($statements->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $statements->links() }}
                    </div>
                @endif
            @else
                <div class="p-12 text-center">
                    <div class="text-6xl mb-4">📋</div>
                    <h3 class="text-lg font-medium text-gray-800 mb-2">No Transactions Yet</h3>
                    <p class="text-gray-600">When you participate in expenses or make payments, they'll appear here.</p>
                </div>
            @endif
        </div>
    </div>

    <script>
        function regenerateStatements() {
            if (confirm('This will regenerate all statement records with the latest format. Continue?')) {
                fetch('/api/statements/regenerate-simplified', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    alert('Statements updated successfully! Refreshing page...');
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating statements. Please try again.');
                });
            }
        }
    </script>
@endsection