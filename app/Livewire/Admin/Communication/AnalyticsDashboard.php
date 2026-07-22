<?php

namespace App\Livewire\Admin\Communication;

use App\Models\CommunicationLog;
use Livewire\Component;
use Livewire\WithPagination;

class AnalyticsDashboard extends Component
{
    use WithPagination;

    public $dateRange = '7'; // days

    public $search = '';

    protected $queryString = ['dateRange', 'search'];

    public function render()
    {
        $startDate = now()->subDays((int) $this->dateRange);

        // Stats
        $stats = [
            'total_sent' => CommunicationLog::where('created_at', '>=', $startDate)->count(),
            'failed' => CommunicationLog::where('created_at', '>=', $startDate)->where('status', 'failed')->count(),
            'email_count' => CommunicationLog::where('created_at', '>=', $startDate)->where('channel', 'email')->count(),
            'push_count' => CommunicationLog::where('created_at', '>=', $startDate)->where('channel', 'push')->count(),
        ];

        // Logs
        $logs = CommunicationLog::with('user')
            ->where('created_at', '>=', $startDate)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', '%'.$this->search.'%')
                        ->orWhere('correlation_id', 'like', '%'.$this->search.'%')
                        ->orWhereHas('user', function ($userQ) {
                            $userQ->where('email', 'like', '%'.$this->search.'%')
                                ->orWhere('name', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->latest()
            ->paginate(20);

        return view('livewire.admin.communication.analytics-dashboard', [
            'stats' => $stats,
            'logs' => $logs,
        ])->layout('layouts.admin'); // Adjust layout name if different
    }
}
