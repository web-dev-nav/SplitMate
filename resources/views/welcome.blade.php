@extends('layouts.welcome')

@section('title', 'SplitMate - Welcome')

@section('content')
    <div class="text-[13px] leading-[20px] flex-1 p-6 pb-12 lg:p-20 bg-white dark:bg-[#161615] dark:text-[#EDEDEC] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none">
        <h1 class="mb-1 font-medium">Welcome to SplitMate</h1>
        <p class="mb-2 text-[#706f6c] dark:text-[#A1A09A]">Simple expense splitting made easy. <br>Track shared expenses and settle debts effortlessly.</p>
        
        <div class="flex flex-col mb-4 lg:mb-6">
            <div class="flex items-center gap-4 py-2 relative before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] before:top-1/2 before:bottom-0 before:left-[0.4rem] before:absolute">
                <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold text-sm">
                    1
                </div>
                <div>
                    <h3 class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Add People</h3>
                    <p class="text-[#706f6c] dark:text-[#A1A09A] text-sm">Set up your group members</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4 py-2 relative before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] before:top-1/2 before:bottom-0 before:left-[0.4rem] before:absolute">
                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center text-green-600 dark:text-green-400 font-bold text-sm">
                    2
                </div>
                <div>
                    <h3 class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Track Expenses</h3>
                    <p class="text-[#706f6c] dark:text-[#A1A09A] text-sm">Record shared bills and payments</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4 py-2 relative">
                <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center text-purple-600 dark:text-purple-400 font-bold text-sm">
                    3
                </div>
                <div>
                    <h3 class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Settle Debts</h3>
                    <p class="text-[#706f6c] dark:text-[#A1A09A] text-sm">See who owes what and record payments</p>
                </div>
            </div>
        </div>

        <div class="text-center">
            <a href="{{ route('expenses.index') }}" 
               class="inline-block bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-colors font-medium">
                Get Started with SplitMate
            </a>
        </div>
    </div>
@endsection

