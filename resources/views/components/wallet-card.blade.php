@props(['balance', 'users'])

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
        <div class="space-y-0.5 text-center">
            <p class="text-xs font-medium text-green-600">Wallet Balance:</p>
            <p class="text-base font-bold text-green-600">+${{ number_format($netAmount, 2) }}</p>
            <p class="text-xs text-gray-600">Will receive from others</p>
        </div>
    @elseif($netAmount < 0)
        <div class="space-y-0.5 text-center">
            <p class="text-xs font-medium text-red-600">Wallet Balance:</p>
            <p class="text-base font-bold text-red-600">-${{ number_format(abs($netAmount), 2) }}</p>
            <p class="text-xs text-gray-600">Needs to pay others</p>
        </div>
    @else
        <div class="space-y-0.5 text-center">
            <p class="text-sm font-bold text-gray-600">All settled!</p>
            <p class="text-xs text-gray-500">$0.00</p>
        </div>
    @endif

    @if(count($balance['owes']) > 0 || count($balance['owed_by']) > 0)
        <div class="mt-2 pt-2 border-t border-gray-200 text-center">
            @if(count($balance['owes']) > 0)
                <div class="mb-1">
                    <p class="text-xs font-medium text-red-600 mb-1">Needs to pay:</p>
                    @foreach($balance['owes'] as $toUserId => $amount)
                        @php $toUser = $users->find($toUserId) @endphp
                        <p class="text-xs text-red-600 truncate">
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
                        <p class="text-xs text-green-600 truncate">
                            ${{ number_format($amount, 2) }} from {{ $fromUser->name }}
                        </p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>

