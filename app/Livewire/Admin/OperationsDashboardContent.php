<?php

namespace App\Livewire\Admin;

use App\Models\BlogPost;
use App\Models\ContactMessage;
use App\Models\NotificationCampaign;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class OperationsDashboardContent extends Component
{
    public function render()
    {
        $today = Carbon::today();

        // KPIs
        $totalBlogPosts = BlogPost::count();
        $pendingTickets = ContactMessage::where('status', 'new')->count();
        $activeCampaigns = NotificationCampaign::whereIn('status', ['active', 'running', 'scheduled'])->count();
        $newUsersToday = User::whereDate('created_at', '>=', $today)->count();

        // Tables
        $latestTickets = ContactMessage::orderByDesc('created_at')->take(5)->get();
        $latestUsers = User::orderByDesc('created_at')->take(5)->get();

        return view('livewire.admin.operations-dashboard-content', [
            'totalBlogPosts' => $totalBlogPosts,
            'pendingTickets' => $pendingTickets,
            'activeCampaigns' => $activeCampaigns,
            'newUsersToday' => $newUsersToday,
            'latestTickets' => $latestTickets,
            'latestUsers' => $latestUsers,
        ]);
    }
}
