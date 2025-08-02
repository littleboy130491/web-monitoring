<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Status - Web Monitor</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Service Status</h1>
            <p class="text-gray-600">Real-time monitoring of our services</p>
        </div>

        <!-- Overall Status -->
        <div class="bg-white rounded-lg shadow-sm border mb-8 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900">Overall Status</h2>
                    <p class="text-gray-600 mt-1">
                        @if($overallStatus === 'operational')
                            All systems operational
                        @else
                            {{ $downCount + $errorCount }} service(s) experiencing issues
                        @endif
                    </p>
                </div>
                <div class="flex items-center">
                    @if($overallStatus === 'operational')
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
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                    <span class="text-sm text-gray-600">Online</span>
                </div>
                <div class="text-2xl font-bold text-gray-900 mt-1">{{ $upCount }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="w-2 h-2 bg-red-500 rounded-full mr-2"></div>
                    <span class="text-sm text-gray-600">Offline</span>
                </div>
                <div class="text-2xl font-bold text-gray-900 mt-1">{{ $downCount + $errorCount }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="w-2 h-2 bg-gray-400 rounded-full mr-2"></div>
                    <span class="text-sm text-gray-600">Total</span>
                </div>
                <div class="text-2xl font-bold text-gray-900 mt-1">{{ $totalWebsites }}</div>
            </div>
        </div>

        <!-- Services -->
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="px-6 py-4 border-b">
                <h2 class="text-lg font-semibold text-gray-900">Services</h2>
            </div>
            <div class="divide-y">
                @forelse($websites as $website)
                    <div class="px-6 py-4 flex items-center justify-between">
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
                                <p class="text-sm text-gray-500">{{ $website->description }}</p>
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
                        No services configured yet.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-sm text-gray-500">
            <p>This page auto-refreshes every 30 seconds</p>
            <p class="mt-2">
                <a href="/admin" class="text-blue-600 hover:text-blue-500">Admin Panel</a>
            </p>
        </div>
    </div>

    <script>
        // Auto-refresh page every 30 seconds
        setTimeout(function() {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>