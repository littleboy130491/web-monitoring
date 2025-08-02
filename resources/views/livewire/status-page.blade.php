<div class="max-w-6xl mx-auto px-4 py-8">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Service Status</h1>
        <p class="text-gray-600">Monitor the status of our services</p>
    </div>

    <!-- Overall Status -->
    <div class="bg-white rounded-lg shadow-sm border mb-8 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">Overall Status</h2>
                <p class="text-gray-600 mt-1">
                    @if($stats['overallStatus'] === 'operational')
                        All systems operational
                    @else
                        {{ $stats['downCount'] }} service(s) experiencing issues
                    @endif
                </p>
            </div>
            <div class="flex items-center">
                @if($stats['overallStatus'] === 'operational')
                    <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                    <span class="text-green-700 font-medium">Operational</span>
                @else
                    <div class="w-3 h-3 bg-red-500 rounded-full mr-2"></div>
                    <span class="text-red-700 font-medium">Issues Detected</span>
                @endif
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow-sm border p-4 cursor-pointer hover:bg-gray-50 transition-colors {{ $statusFilter === 'online' ? 'ring-2 ring-green-500' : '' }}"
             wire:click="setStatusFilter('online')">
            <div class="flex items-center">
                <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                <span class="text-sm text-gray-600">Online</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $stats['upCount'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border p-4 cursor-pointer hover:bg-gray-50 transition-colors {{ $statusFilter === 'offline' ? 'ring-2 ring-red-500' : '' }}"
             wire:click="setStatusFilter('offline')">
            <div class="flex items-center">
                <div class="w-2 h-2 bg-red-500 rounded-full mr-2"></div>
                <span class="text-sm text-gray-600">Offline</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $stats['downCount'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border p-4 cursor-pointer hover:bg-gray-50 transition-colors {{ $statusFilter === 'all' ? 'ring-2 ring-gray-500' : '' }}"
             wire:click="setStatusFilter('all')">
            <div class="flex items-center">
                <div class="w-2 h-2 bg-gray-400 rounded-full mr-2"></div>
                <span class="text-sm text-gray-600">Total</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $stats['totalWebsites'] }}</div>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="bg-white rounded-lg shadow-sm border mb-6 p-4">
        <div class="flex flex-col sm:flex-row gap-4 items-center justify-between">
            <div class="flex items-center space-x-4">
                <span class="text-sm font-medium text-gray-700">Filter:</span>
                <div class="flex space-x-2">
                    <button wire:click="setStatusFilter('all')"
                            class="px-3 py-1 text-sm rounded-full transition-colors
                                   {{ $statusFilter === 'all' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                        All ({{ $stats['totalWebsites'] }})
                    </button>
                    <button wire:click="setStatusFilter('online')"
                            class="px-3 py-1 text-sm rounded-full transition-colors
                                   {{ $statusFilter === 'online' ? 'bg-green-600 text-white' : 'bg-green-100 text-green-700 hover:bg-green-200' }}">
                        Online ({{ $stats['upCount'] }})
                    </button>
                    <button wire:click="setStatusFilter('offline')"
                            class="px-3 py-1 text-sm rounded-full transition-colors
                                   {{ $statusFilter === 'offline' ? 'bg-red-600 text-white' : 'bg-red-100 text-red-700 hover:bg-red-200' }}">
                        Offline ({{ $stats['downCount'] }})
                    </button>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <label for="search" class="text-sm font-medium text-gray-700">Search:</label>
                <input type="text" 
                       id="search"
                       wire:model.live.debounce.300ms="search" 
                       placeholder="Search services..."
                       class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
        </div>
    </div>

    <!-- Services -->
    <div class="bg-white rounded-lg shadow-sm border">
        <div class="px-6 py-4 border-b">
            <h2 class="text-lg font-semibold text-gray-900">Services</h2>
            <p class="text-sm text-gray-500 mt-1">
                Showing {{ $websites->count() }} of {{ $websites->total() }} services
                @if($statusFilter !== 'all')
                    (filtered by {{ $statusFilter }})
                @endif
                @if(!empty($search))
                    matching "{{ $search }}"
                @endif
            </p>
        </div>
        <div class="divide-y">
            @forelse($websites as $website)
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 mr-4">
                            @if($website->latestResult)
                                @if($website->latestResult->status === 'up')
                                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                @elseif($website->latestResult->status === 'down')
                                    <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                @elseif($website->latestResult->status === 'error')
                                    <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                @else
                                    <div class="w-3 h-3 bg-yellow-500 rounded-full"></div>
                                @endif
                            @else
                                <div class="w-3 h-3 bg-gray-400 rounded-full"></div>
                            @endif
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-900">{{ $website->name }}</h3>
                            @if($website->description)
                                <p class="text-sm text-gray-500">{{ $website->description }}</p>
                            @endif
                            <p class="text-xs text-gray-400">{{ $website->url }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        @if($website->latestResult)
                            <div class="flex items-center space-x-4">
                                @if($website->latestResult->response_time)
                                    <span class="text-sm text-gray-600">
                                        {{ $website->latestResult->response_time }}ms
                                    </span>
                                @endif
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    @if($website->latestResult->status === 'up') bg-green-100 text-green-800
                                    @elseif($website->latestResult->status === 'down') bg-red-100 text-red-800
                                    @elseif($website->latestResult->status === 'error') bg-red-100 text-red-800
                                    @else bg-yellow-100 text-yellow-800 @endif">
                                    {{ ucfirst($website->latestResult->status) }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                Last checked {{ $website->latestResult->checked_at->diffForHumans() }}
                            </div>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                Unknown
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-6 py-8 text-center text-gray-500">
                    @if($statusFilter !== 'all' || !empty($search))
                        No services found matching your criteria.
                        <button wire:click="$set('statusFilter', 'all'); $set('search', '')" 
                                class="text-blue-600 hover:text-blue-500 ml-1">
                            Clear filters
                        </button>
                    @else
                        No services configured yet.
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <!-- Pagination -->
    @if($websites->hasPages())
        <div class="mt-8">
            {{ $websites->links() }}
        </div>
    @endif

    <!-- Footer -->
    <div class="text-center mt-8 text-sm text-gray-500">
        <p class="mt-2">
            <a href="/admin" class="text-blue-600 hover:text-blue-500">Admin Panel</a>
        </p>
    </div>
</div>
