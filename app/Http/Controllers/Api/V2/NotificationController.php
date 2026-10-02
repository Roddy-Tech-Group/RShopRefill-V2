<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Communication\Services\PreferenceManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\NotificationResource;
use App\Http\Responses\V2\CursorPage;
use App\Models\Notification;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification tray, and what the customer wants to be told about.
 *
 * Marking as read is a PATCH and is idempotent by nature: marking an already-read
 * notification read again changes nothing, so none of these need an idempotency
 * key.
 */
class NotificationController extends Controller
{
    /**
     * Master switch per channel: off here and that channel is silent whatever the
     * per-category settings say.
     *
     * Only the channels the pipeline actually HONOURS. The preferences table also
     * has sms and whatsapp columns, but nothing reads them yet, so a switch for
     * them would be a switch that does nothing, and would still look like a promise
     * on the day those channels ship.
     */
    private const CHANNELS = [
        'email' => 'email_enabled',
        'push' => 'push_enabled',
    ];

    /**
     * What a customer can be told about. `security` is deliberately not here: there
     * is no column for it because those notices cannot be switched off.
     */
    private const CATEGORIES = ['wallet', 'orders', 'transactions', 'product_updates', 'marketing'];

    public function __construct(private PreferenceManager $preferenceService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $notifications = Notification::where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            // Newest first, then id, so cursor paging is total and a notification
            // arriving mid-scroll cannot push another off the page unseen.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                CursorPage::perPage($validated['per_page'] ?? null),
                ['*'],
                'cursor',
                CursorPage::cursor($validated['cursor'] ?? null, ['created_at', 'id']),
            );

        return CursorPage::respond($notifications, NotificationResource::class);
    }

    /** For the badge on the app's tab bar. Deliberately tiny and cheap to poll. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread' => Notification::where('user_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $row = Notification::where('user_id', $request->user()->id)
            ->whereKey($notification)
            ->first();

        if ($row === null) {
            return ApiV2Error::make('notification_not_found', 'That notification no longer exists.', 404);
        }

        // Only if it is not already read, so re-reading does not rewrite the
        // timestamp and reorder somebody's tray.
        if ($row->read_at === null) {
            $row->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => (new NotificationResource($row))->resolve()]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $marked = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['marked_read' => $marked]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->shape($this->preferenceService->getPreferences($request->user())),
        ]);
    }

    /**
     * Change one switch, or several, without disturbing the rest.
     *
     * Every field is `sometimes`: the app sends only what the customer just toggled,
     * so it cannot reset a preference it never showed them by omitting it. That
     * matters more on a phone than on the web, where a settings page tends to submit
     * everything at once.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.email' => ['sometimes', 'boolean'],
            'channels.push' => ['sometimes', 'boolean'],

            'categories' => ['sometimes', 'array'],
            'categories.wallet.email' => ['sometimes', 'boolean'],
            'categories.wallet.push' => ['sometimes', 'boolean'],
            'categories.orders.email' => ['sometimes', 'boolean'],
            'categories.orders.push' => ['sometimes', 'boolean'],
            'categories.transactions.email' => ['sometimes', 'boolean'],
            'categories.transactions.push' => ['sometimes', 'boolean'],
            'categories.product_updates.email' => ['sometimes', 'boolean'],
            'categories.product_updates.push' => ['sometimes', 'boolean'],
            'categories.marketing.email' => ['sometimes', 'boolean'],
            'categories.marketing.push' => ['sometimes', 'boolean'],
        ]);

        $columns = [];

        foreach (self::CHANNELS as $key => $column) {
            if (array_key_exists($key, (array) ($validated['channels'] ?? []))) {
                $columns[$column] = (bool) $validated['channels'][$key];
            }
        }

        foreach (self::CATEGORIES as $category) {
            foreach (['email', 'push'] as $channel) {
                $value = data_get($validated, "categories.{$category}.{$channel}");

                if ($value !== null) {
                    $columns[$category.'_'.$channel] = (bool) $value;
                }
            }
        }

        if ($columns === []) {
            return ApiV2Error::make(
                'no_preferences_given',
                'Nothing was changed. Please choose a setting to update.',
                422,
            );
        }

        return response()->json([
            'data' => $this->shape($this->preferenceService->updatePreferences($request->user(), $columns)),
        ]);
    }

    /**
     * The preferences as the app sees them: which channels are on at all, and then
     * which categories may use each channel.
     *
     * SECURITY notices are absent on purpose, and that is not an omission. There is
     * no column to switch them off, because "somebody signed in from a new device"
     * is not a message a customer should be able to stop, and the person most likely
     * to want it stopped is whoever stole the account.
     *
     * An explicit map rather than the model, so a column added to that table
     * tomorrow is not published by accident.
     *
     * @return array{channels: array<string, bool>, categories: array<string, array<string, bool>>}
     */
    private function shape(object $preferences): array
    {
        $channels = [];

        foreach (self::CHANNELS as $key => $column) {
            $channels[$key] = (bool) $preferences->{$column};
        }

        $categories = [];

        foreach (self::CATEGORIES as $category) {
            $categories[$category] = [
                'email' => (bool) $preferences->{$category.'_email'},
                'push' => (bool) $preferences->{$category.'_push'},
            ];
        }

        return ['channels' => $channels, 'categories' => $categories];
    }
}
