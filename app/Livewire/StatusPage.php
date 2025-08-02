<?php

namespace App\Livewire;

use App\Models\Website;
use Livewire\Component;
use Livewire\WithPagination;

class StatusPage extends Component
{
    use WithPagination;

    public $statusFilter = 'all'; // all, online, offline
    public $search = '';

    protected $queryString = [
        'statusFilter' => ['except' => 'all'],
        'search' => ['except' => ''],
        'page' => ['except' => 1],
    ];

    public function mount()
    {
        // Initialize component
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function setStatusFilter($status)
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function getWebsitesProperty()
    {
        $query = Website::with('latestResult')
            ->where('is_active', true);

        // Apply search filter
        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('url', 'like', '%' . $this->search . '%')
                  ->orWhere('description', 'like', '%' . $this->search . '%');
            });
        }

        // Apply status filter
        if ($this->statusFilter === 'online') {
            $query->whereHas('latestResult', function ($q) {
                $q->where('status', 'up');
            });
        } elseif ($this->statusFilter === 'offline') {
            $query->where(function ($q) {
                $q->whereHas('latestResult', function ($subQuery) {
                    $subQuery->whereIn('status', ['down', 'error']);
                })->orWhereDoesntHave('latestResult');
            });
        }

        return $query->orderBy('name')->paginate(10);
    }

    public function getStatsProperty()
    {
        $websites = Website::with('latestResult')->where('is_active', true)->get();
        
        $upCount = $websites->filter(function ($website) {
            return $website->latestResult && $website->latestResult->status === 'up';
        })->count();

        $downCount = $websites->filter(function ($website) {
            return !$website->latestResult || in_array($website->latestResult->status, ['down', 'error']);
        })->count();

        $totalWebsites = $websites->count();
        $overallStatus = ($downCount === 0) ? 'operational' : 'issues';

        return [
            'upCount' => $upCount,
            'downCount' => $downCount,
            'totalWebsites' => $totalWebsites,
            'overallStatus' => $overallStatus,
        ];
    }

    public function render()
    {
        return view('livewire.status-page', [
            'websites' => $this->websites,
            'stats' => $this->stats,
        ]);
    }
}
