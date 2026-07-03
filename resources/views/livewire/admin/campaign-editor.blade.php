<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Domain\Notification\Jobs\DispatchCampaignJob;
use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;

new
#[Layout('components.layouts.admin')]
#[Title('Campaign Editor')]
class extends Component {
    public ?NotificationCampaign $campaign = null;
    
    // Form fields
    public string $title = '';
    public string $category = 'marketing';
    public string $audience = 'all';
    
    // Template fields
    public string $pushTitle = '';
    public string $pushBody = '';
    public string $pushUrl = '/dashboard';
    
    // Scheduling
    public string $scheduleType = 'now';
    public string $scheduledFor = '';
    
    public function mount($id = null)
    {
        if ($id) {
            $this->campaign = NotificationCampaign::findOrFail($id);
            $this->title = $this->campaign->title;
            $this->category = $this->campaign->category;
            $this->audience = $this->campaign->audience_type;
            
            $this->pushTitle = $this->campaign->notification_title ?? '';
            $this->pushBody = $this->campaign->notification_message ?? '';
            $this->pushUrl = $this->campaign->notification_url ?? '';
            
            if ($this->campaign->scheduled_at) {
                $this->scheduleType = 'later';
                $this->scheduledFor = $this->campaign->scheduled_at->format('Y-m-d\TH:i');
            }
        }
    }

    public function save()
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string',
            'pushTitle' => 'required|string|max:255',
            'pushBody' => 'required|string',
            // Only enforce a (future) date when scheduling for later.
            'scheduledFor' => $this->scheduleType === 'later' ? 'required|date|after:now' : 'nullable',
        ]);

        $sendNow = $this->scheduleType === 'now';

        $campaignData = [
            'title' => $this->title,
            'notification_title' => $this->pushTitle,
            'notification_message' => $this->pushBody,
            'notification_url' => $this->pushUrl,
            'channels' => ['push'], // Currently push only for campaigns
            'category' => $this->category,
            'audience_type' => $this->audience,
            'audience_filters' => $this->audience === 'all' ? [] : ['active_last_30_days' => true],
            // Send-now claims the campaign as 'processing' up front so the
            // per-minute cron can never also pick it up; a scheduled send stays
            // 'scheduled' for campaigns:dispatch to fire at the chosen time.
            'status' => $sendNow ? 'processing' : 'scheduled',
            'scheduled_at' => $sendNow ? now() : $this->scheduledFor,
        ];

        if ($this->campaign) {
            $this->campaign->update($campaignData);
            $campaign = $this->campaign;
        } else {
            $campaignData['admin_id'] = auth('admin')->id();
            $campaign = NotificationCampaign::create($campaignData);
        }

        if ($sendNow) {
            // True send-now: hand the fan-out straight to the queue so it goes
            // out within seconds (Horizon) rather than on the next cron tick.
            DispatchCampaignJob::dispatch($campaign);

            return redirect()->route('admin.campaigns')->with('success', 'Campaign is sending now.');
        }

        return redirect()->route('admin.campaigns')
            ->with('success', 'Campaign scheduled for '.$campaign->scheduled_at->format('M j, Y g:i A').'.');
    }
};
?>

<div>
    <x-slot:heading>{{ $campaign ? 'Edit Campaign' : 'Create Campaign' }}</x-slot:heading>
    <x-slot:subheading>Design and target your push notification.</x-slot:subheading>

    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-end gap-3">
            <flux:button href="{{ route('admin.campaigns') }}" wire:navigate variant="ghost">Cancel</flux:button>
            <flux:button wire:click="save" variant="primary" icon="paper-airplane">
                <span x-text="$wire.scheduleType === 'now' ? 'Send Now' : 'Schedule'">Send Now</span>
            </flux:button>
        </div>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            
            {{-- Editor Form --}}
            <div class="lg:col-span-2 space-y-8">
                
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Campaign Settings</flux:heading>
                    <div class="space-y-5">
                        <flux:field>
                            <flux:label>Campaign Name</flux:label>
                            <flux:input wire:model="title" placeholder="e.g., Summer Sale Announcement" />
                            <flux:error name="title" />
                        </flux:field>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Category</flux:label>
                                <flux:select wire:model="category">
                                    <option value="marketing">Marketing</option>
                                    <option value="engagement">Engagement</option>
                                    <option value="travel">Travel Alerts</option>
                                </flux:select>
                            </flux:field>

                            <flux:field>
                                <flux:label>Audience</flux:label>
                                <flux:select wire:model="audience">
                                    <option value="all">All Subscribed Users</option>
                                    <option value="segment">Active Users (Last 30 Days)</option>
                                </flux:select>
                            </flux:field>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <flux:heading size="lg" class="mb-4">Message Content</flux:heading>
                    <div class="space-y-5">
                        <flux:field>
                            <flux:label>Notification Title</flux:label>
                            <flux:input wire:model.live="pushTitle" placeholder="Limited Time Offer!" maxlength="100" />
                            <flux:error name="pushTitle" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Message Body</flux:label>
                            <flux:textarea wire:model.live="pushBody" placeholder="Get 20% off all eSIM purchases this weekend." rows="3" maxlength="255" />
                            <flux:error name="pushBody" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Click Action URL</flux:label>
                            <flux:input wire:model="pushUrl" placeholder="/dashboard" />
                            <flux:description>Where the user is taken when they click the notification.</flux:description>
                        </flux:field>
                    </div>
                </flux:card>

                <flux:card>
                    <flux:heading size="lg" class="mb-4">Scheduling</flux:heading>
                    <div class="space-y-5">
                        <flux:radio.group wire:model.live="scheduleType" label="When should this send?">
                            <flux:radio value="now" label="Send Immediately" />
                            <flux:radio value="later" label="Schedule for Later" />
                        </flux:radio.group>

                        <div x-show="$wire.scheduleType === 'later'" x-collapse>
                            <flux:field>
                                <flux:label>Schedule Date & Time</flux:label>
                                <flux:input type="datetime-local" wire:model="scheduledFor" />
                            </flux:field>
                        </div>
                    </div>
                </flux:card>
                
            </div>

            {{-- Live Preview --}}
            <div class="lg:col-span-1">
                <div class="sticky top-24">
                    <flux:heading size="lg" class="mb-4">OS Preview</flux:heading>
                    
                    {{-- Fake Windows 11 Notification --}}
                    <div class="rounded-xl bg-[#2b2b2b] p-4 text-left shadow-2xl ring-1 ring-white/10 overflow-hidden transform transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <img src="{{ asset('assets/icon-192.png') }}" class="w-4 h-4 rounded-sm" alt="{{ $siteName }}">
                                <span class="text-xs text-zinc-300 font-medium">{{ $siteName }}</span>
                            </div>
                            <span class="text-xs text-zinc-400">{{ now()->format('h:i A') }}</span>
                        </div>
                        
                        <div class="flex gap-4">
                            <div class="flex-1">
                                <h4 class="text-sm font-semibold text-white truncate max-w-[200px]">
                                    {{ $pushTitle ?: 'Notification Title' }}
                                </h4>
                                <p class="text-xs text-zinc-300 mt-1 line-clamp-3 leading-relaxed">
                                    {{ $pushBody ?: 'This is how your message will look when delivered to the user\'s desktop or mobile device.' }}
                                </p>
                            </div>
                            <div class="w-16 h-16 shrink-0 bg-white/5 rounded flex items-center justify-center p-1">
                                <img src="{{ asset('assets/icon-512.png') }}" class="w-full h-full object-contain rounded-sm" alt="App Icon">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 text-sm text-zinc-500 dark:text-zinc-400 bg-blue-50 dark:bg-blue-500/10 p-4 rounded-xl border border-blue-100 dark:border-blue-500/20">
                        <p class="font-medium text-blue-800 dark:text-blue-300 mb-1 flex items-center gap-2">
                            <flux:icon.information-circle class="w-4 h-4" /> Preview Note
                        </p>
                        Actual appearance varies by operating system (Windows, macOS, Android) and browser version.
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
