<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SplitMate - Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-6 px-4">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-5xl font-bold text-gray-800 mb-2">⚙️ Settings</h1>
            <p class="text-gray-600 text-lg">Manage people and app preferences</p>
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

        <!-- Back Button -->
        <div class="mb-6">
            <a href="{{ route('expenses.index') }}" 
               class="inline-flex items-center text-blue-600 hover:text-blue-800 font-medium">
                ← Back to SplitMate
            </a>
        </div>

        <!-- Manage People -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">👥 Manage People</h2>
            
            <form action="{{ route('settings.update-users') }}" method="POST" id="usersForm">
                @csrf
                <div id="usersContainer" class="space-y-4">
                    @foreach($users as $index => $user)
                        <div class="user-row flex gap-4 items-end p-4 bg-gray-50 rounded-xl" data-index="{{ $index }}">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                                <input type="text" name="users[{{ $index }}][name]" value="{{ $user->name }}" required
                                       class="w-full border-2 border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                                <input type="hidden" name="users[{{ $index }}][id]" value="{{ $user->id }}">
                            </div>
                            <div class="flex gap-2">
                                @if($users->count() > 2)
                                    <button type="button" onclick="removeUser(this)" 
                                            class="bg-red-100 text-red-700 px-3 py-2 rounded-lg hover:bg-red-200 transition-colors"
                                            title="Remove from current group">
                                        👻
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-4 mt-6">
                    <button type="button" onclick="addUser()" 
                            class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                        ➕ Add Person
                    </button>
                    <button type="submit" 
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        💾 Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- App Info -->
        <div class="bg-white rounded-2xl shadow-lg p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">ℹ️ App Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="text-center p-4 bg-blue-50 rounded-xl">
                    <div class="text-3xl mb-2">👥</div>
                    <h3 class="font-bold text-lg text-gray-800">Total People</h3>
                    <p class="text-2xl font-bold text-blue-600">{{ $users->count() }}</p>
                </div>
                
                <div class="text-center p-4 bg-green-50 rounded-xl">
                    <div class="text-3xl mb-2">💰</div>
                    <h3 class="font-bold text-lg text-gray-800">Expense System</h3>
                    <p class="text-sm text-gray-600">Split equally among all people</p>
                </div>
            </div>

            @if($inactiveUsers->count() > 0)
            <!-- Former Members Section -->
            <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">👻 Former Members</h2>
                
                <div class="space-y-4">
                    @foreach($inactiveUsers as $user)
                        <div class="flex items-center justify-between p-4 bg-gray-100 rounded-xl">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-gray-400 rounded-full flex items-center justify-center text-white font-bold">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800">{{ $user->name }}</div>
                                    <div class="text-sm text-gray-500">Removed from current group</div>
                                </div>
                            </div>
                            <form action="{{ route('settings.reactivate-user', $user) }}" method="POST" class="inline">
                                @csrf
                                <button type="submit" 
                                        class="bg-green-100 text-green-700 px-4 py-2 rounded-lg hover:bg-green-200 transition-colors text-sm">
                                    🔄 Reactivate
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
                
                <div class="mt-4 p-3 bg-blue-50 rounded-xl">
                    <p class="text-sm text-blue-700">
                        <strong>Note:</strong> Former members can be reactivated if they return. 
                        Their historical transactions are preserved.
                    </p>
                </div>
            </div>
            @endif

            <div class="mt-6 p-4 bg-yellow-50 rounded-xl">
                <h3 class="font-bold text-lg text-yellow-800 mb-2">⚠️ Important Notes</h3>
                <ul class="text-sm text-yellow-700 space-y-1">
                    <li>• You need at least 2 people to use the app</li>
                    <li>• Maximum 10 people supported</li>
                    <li>• Removing a person preserves their transaction history</li>
                    <li>• All expenses are split equally among all people</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        let userIndex = {{ $users->count() }};

        function addUser() {
            const container = document.getElementById('usersContainer');
            const newUserHtml = `
                <div class="user-row flex gap-4 items-end p-4 bg-gray-50 rounded-xl" data-index="${userIndex}">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="users[${userIndex}][name]" required
                               class="w-full border-2 border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="Enter name">
                        <input type="hidden" name="users[${userIndex}][id]" value="">
                    </div>
                    <div class="flex gap-2">
                        <button type="button" onclick="removeUser(this)" 
                                class="bg-red-100 text-red-700 px-3 py-2 rounded-lg hover:bg-red-200 transition-colors">
                            🗑️
                        </button>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', newUserHtml);
            userIndex++;
        }

        function removeUser(button) {
            const userRow = button.closest('.user-row');
            userRow.remove();
        }
    </script>
</body>
</html>
