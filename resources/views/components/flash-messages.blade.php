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

