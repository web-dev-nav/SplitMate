@extends('layouts.app')

@section('title', $user->name . ' - Account Statement')
@section('subtitle', 'Complete transaction history and balance details')

@section('content')
    <div class="max-w-6xl mx-auto">
        <!-- User Navigation Bar -->
        <div class="bg-white rounded-xl shadow-sm mb-6 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold text-gray-800">Account Statements</h2>
                <a href="{{ route('expenses.index') }}"
                   class="text-sm text-blue-600 hover:text-blue-800">← Back to Dashboard</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($allUsers as $navUser)
                    <a href="{{ route('statements.user', $navUser->id) }}"
                       class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $navUser->id == $user->id ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                        {{ $navUser->name }}
                        @if($navUser->id == $user->id)
                            <span class="ml-1">📋</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>

        <!-- Statement Header -->
        <div class="bg-gradient-to-r from-gray-800 to-gray-600 text-white rounded-t-2xl p-6 shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">{{ $user->name }}</h1>
                    <p class="text-gray-200 mt-1">Account Statement</p>
                    <p class="text-sm text-gray-300">SplitMate Financial Services</p>
                </div>
                <div class="text-right">
                    <div class="text-sm text-gray-300">Current Balance</div>
                    <div class="text-3xl font-bold {{ $currentBalance >= 0 ? 'text-green-300' : 'text-red-300' }}">
                        {{ $currentBalance >= 0 ? '+' : '' }}${{ number_format($currentBalance, 2) }}
                    </div>
                    <div class="text-xs text-gray-400 mt-1">as of {{ date('M j, Y') }}</div>
                </div>
            </div>
        </div>

        <!-- Statement Summary -->
        <div class="bg-white border-l-4 border-r-4 border-gray-200 p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div class="bg-blue-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-600">{{ $statements->count() }}</div>
                    <div class="text-sm text-gray-600">Total Transactions</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600">
                        {{ $statements->where('transaction_type', 'expense')->count() }}
                    </div>
                    <div class="text-sm text-gray-600">Expenses</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-600">
                        {{ $statements->where('transaction_type', 'settlement')->count() }}
                    </div>
                    <div class="text-sm text-gray-600">Payments</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-4">
                    @php
                        $dateRange = $statements->count() > 0 ?
                            \Carbon\Carbon::parse($statements->last()->transaction_date)->diffInDays($statements->first()->transaction_date) + 1 : 0;
                    @endphp
                    <div class="text-2xl font-bold text-orange-600">{{ $dateRange }}</div>
                    <div class="text-sm text-gray-600">Days</div>
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="bg-white rounded-b-2xl shadow-lg overflow-hidden">
            <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-800">Transaction History</h2>
                <p class="text-sm text-gray-600">Chronological list of all account activity</p>
            </div>

            @if($statements->count() > 0)
                <div class="overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-100 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($statements as $statement)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $statement->transaction_date->format('M j, Y') }}
                                        <div class="text-xs text-gray-400">{{ $statement->transaction_date->format('g:i A') }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-mono text-gray-800">{{ $statement->reference_number }}</div>
                                        <div class="flex items-center mt-1">
                                            <span class="px-2 py-1 text-xs rounded-full {{ $statement->transaction_type == 'expense' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">
                                                {{ ucfirst($statement->transaction_type) }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-800 max-w-xs">
                                        <div class="font-medium">{{ $statement->description }}</div>

                                        @if($statement->transaction_details && isset($statement->transaction_details['affected_balances']))
                                            @php
                                                $balancesBefore = $statement->transaction_details['affected_balances']['owes_before'] ?? [];
                                                $balancesAfter = $statement->transaction_details['affected_balances']['owes_after'] ?? [];
                                                $receivesBefore = $statement->transaction_details['affected_balances']['receives_before'] ?? [];
                                                $receivesAfter = $statement->transaction_details['affected_balances']['receives_after'] ?? [];

                                                // Calculate changes in what user owes to others
                                                $owesChanges = [];
                                                $allOwesUsers = array_unique(array_merge(array_keys($balancesBefore), array_keys($balancesAfter)));
                                                foreach($allOwesUsers as $userId) {
                                                    $before = $balancesBefore[$userId] ?? 0;
                                                    $after = $balancesAfter[$userId] ?? 0;
                                                    $change = $after - $before;
                                                    if (abs($change) >= 0.01) {
                                                        $owesChanges[$userId] = $change;
                                                    }
                                                }

                                                // Calculate changes in what user receives from others
                                                $receivesChanges = [];
                                                $allReceivesUsers = array_unique(array_merge(array_keys($receivesBefore), array_keys($receivesAfter)));
                                                foreach($allReceivesUsers as $userId) {
                                                    $before = $receivesBefore[$userId] ?? 0;
                                                    $after = $receivesAfter[$userId] ?? 0;
                                                    $change = $after - $before;
                                                    if (abs($change) >= 0.01) {
                                                        $receivesChanges[$userId] = $change;
                                                    }
                                                }
                                            @endphp

                                            @if(count($owesChanges) > 0 || count($receivesChanges) > 0)
                                                <div class="mt-2 space-y-1">
                                                    @foreach($owesChanges as $userId => $change)
                                                        @php $otherUser = $allUsers->find($userId) @endphp
                                                        @if($otherUser && $change > 0)
                                                            <div class="text-xs text-red-600">
                                                                +${{ number_format($change, 2) }} owe to {{ $otherUser->name }}
                                                            </div>
                                                        @elseif($otherUser && $change < 0)
                                                            <div class="text-xs text-green-600">
                                                                ${{ number_format(abs($change), 2) }} less owe to {{ $otherUser->name }}
                                                            </div>
                                                        @endif
                                                    @endforeach

                                                    @foreach($receivesChanges as $userId => $change)
                                                        @php $otherUser = $allUsers->find($userId) @endphp
                                                        @if($otherUser && $change > 0)
                                                            <div class="text-xs text-green-600">
                                                                +${{ number_format($change, 2) }} from {{ $otherUser->name }}
                                                            </div>
                                                        @elseif($otherUser && $change < 0)
                                                            <div class="text-xs text-red-600">
                                                                ${{ number_format(abs($change), 2) }} less from {{ $otherUser->name }}
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif

                                            {{-- Show debt reduction details --}}
                                            @if(isset($statement->transaction_details['debt_reductions']) && count($statement->transaction_details['debt_reductions']) > 0)
                                                <div class="mt-2 pt-2 border-t border-gray-200">
                                                    <div class="text-xs font-medium text-orange-600 mb-1">💡 Auto Debt Reduction:</div>
                                                    @foreach($statement->transaction_details['debt_reductions'] as $reduction)
                                                        @if($reduction['type'] === 'debt_reduced')
                                                            <div class="text-xs text-orange-600">
                                                                🔄 ${{ number_format($reduction['amount'], 2) }} debt to {{ $reduction['other_user_name'] }} reduced
                                                                (was ${{ number_format($reduction['before'], 2) }} → now ${{ number_format($reduction['after'], 2) }})
                                                            </div>
                                                        @elseif($reduction['type'] === 'receivable_reduced')
                                                            <div class="text-xs text-orange-600">
                                                                🔄 ${{ number_format($reduction['amount'], 2) }} receivable from {{ $reduction['other_user_name'] }} reduced by your debt
                                                                (was ${{ number_format($reduction['before'], 2) }} → now ${{ number_format($reduction['after'], 2) }})
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        @endif

                                        @if($statement->status !== 'completed')
                                            <div class="text-xs text-orange-600 mt-1">{{ ucfirst($statement->status) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-mono">
                                        <span class="font-bold {{ $statement->balance_change >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $statement->formatted_balance_change }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-mono">
                                        <span class="font-bold {{ $statement->balance_after >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $statement->formatted_balance_after }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-12">
                    <div class="text-6xl mb-4">📄</div>
                    <h3 class="text-xl font-medium text-gray-600 mb-2">No Transactions Yet</h3>
                    <p class="text-gray-500">This account has no transaction history.</p>
                </div>
            @endif
        </div>

        <!-- Actions -->
        <div class="mt-6 flex items-center justify-center gap-4">
            <a href="{{ route('api.statements.user', $user->id) }}"
               target="_blank"
               class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors shadow-sm">
                📊 Download JSON
            </a>
            <button onclick="window.print()"
                    class="inline-flex items-center px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors shadow-sm">
                🖨️ Print Statement
            </button>
        </div>
    </div>

    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
            .shadow-lg { box-shadow: none; }
        }
    </style>
@endsection