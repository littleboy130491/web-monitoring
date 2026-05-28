<?php

namespace App\Http\Controllers;

use App\Models\Website;

class StatusController extends Controller
{
    public function index()
    {
        $websites = Website::where('is_active', true)
            ->with(['latestResult'])
            ->orderBy('url')
            ->get();

        $totalWebsites = $websites->count();
        $upCount = $websites->where('latestResult.status', 'up')->count();
        $downCount = $websites->where('latestResult.status', 'down')->count();
        $errorCount = $websites->where('latestResult.status', 'error')->count();

        $overallStatus = ($downCount + $errorCount) === 0 ? 'operational' : 'issues';

        return view('status', compact('websites', 'totalWebsites', 'upCount', 'downCount', 'errorCount', 'overallStatus'));
    }
}
