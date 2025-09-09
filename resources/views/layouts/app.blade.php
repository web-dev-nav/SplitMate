<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SplitMate - Simple Expense Splitter')</title>
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
    @stack('styles')
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
            <p class="text-gray-600 text-lg">@yield('subtitle', 'Simple expense splitting')</p>
        </div>

        <!-- Flash Messages -->
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
                        <strong>Please fix the following errors:</strong>
                        <ul class="mt-1 list-disc list-inside">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <!-- Main Content -->
        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>

