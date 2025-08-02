<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class MonitorController extends Controller
{
    public function monitor(Website $website)
    {
        try {
            Artisan::call('monitor:websites', ['--id' => $website->id, '--screenshot' => true]);
            
            return back()->with('success', "✅ Monitoring completed for {$website->name}");
        } catch (\Exception $e) {
            return back()->with('error', "❌ Monitoring failed: {$e->getMessage()}");
        }
    }

    public function monitorGet(Website $website)
    {
        try {
            Artisan::call('monitor:websites', ['--id' => $website->id, '--screenshot' => true]);
            
            // Check if request came from monitoring results view
            $referer = request()->headers->get('referer');
            if ($referer && str_contains($referer, 'monitoring-results')) {
                return redirect('/admin/monitoring-results')->with('success', "✅ Monitoring completed for {$website->name}");
            }
            
            return back()->with('success', "✅ Monitoring completed for {$website->name}");
        } catch (\Exception $e) {
            return back()->with('error', "❌ Monitoring failed: {$e->getMessage()}");
        }
    }

    public function monitorAll()
    {
        try {
            Artisan::call('monitor:websites', ['--screenshot' => true]);
            
            return back()->with('success', '✅ Monitoring started for all active websites');
        } catch (\Exception $e) {
            return back()->with('error', "❌ Monitoring failed: {$e->getMessage()}");
        }
    }
}
