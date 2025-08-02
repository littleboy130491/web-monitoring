<form action="{{ route('monitor.website', $getRecord()) }}" method="POST" style="display: inline;">
    @csrf
    <x-filament::button
        type="submit"
        size="xs"
        color="primary"
        icon="heroicon-o-play"
    >
        Monitor
    </x-filament::button>
</form>