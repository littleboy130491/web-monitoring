<div class="flex justify-between items-center mb-4">
    <div>
        <h1 class="text-2xl font-bold">Websites</h1>
        <p class="text-gray-600">Manage and monitor your websites</p>
    </div>
    <div class="flex gap-2">
        <form action="{{ route('monitor.all') }}" method="POST" style="display: inline;">
            @csrf
            <x-filament::button
                type="submit"
                color="success"
                icon="heroicon-o-play"
            >
                Monitor All
            </x-filament::button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="mb-4 p-3 bg-green-100 border border-green-200 text-green-800 rounded">
        <strong>✅ Success:</strong> {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="mb-4 p-3 bg-red-100 border border-red-200 text-red-800 rounded">
        <strong>❌ Error:</strong> {{ session('error') }}
    </div>
@endif