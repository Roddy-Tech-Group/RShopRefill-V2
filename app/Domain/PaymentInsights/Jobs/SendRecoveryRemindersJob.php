<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Mail\Transactions\PaymentRecoveryMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Communication\Services\PreferenceManager;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Models\EmailSuppression;
use App\Models\TransactionRecovery;
use App\Support\FeatureFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The second (and final) recovery touch.
 *
 * One email is a single roll of the dice: it can land while the customer is
 * asleep, in a promotions tab, or in the ten minutes they were not going to
 * read anything. A single reminder is the highest-value message in a recovery
 * sequence and the point at which most abandoned-cart programmes stop, because
 * a third touch converts little and costs sender reputation.
 *
 * Only genuinely silent recoveries qualify. If the customer clicked, retried,
 * gave feedback, or paid, they hear nothing more.
 */
class SendRecoveryRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Wait this long after the first email before nudging. */
    public const REMIND_AFTER_HOURS = 20;

    /** Never send a reminder this close to the link expiring. */
    public const MIN_REMAINING_HOURS = 2;

    public $tries = 3;

    public function __construct()
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('intelligence');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CommunicationManager $communications, PreferenceManager $preferences): void
    {
        if (FeatureFlag::off('payment_insights')) {
            return;
        }

        $due = TransactionRecovery::query()
            // Pending only: any other open state means they engaged, and a
            // "did you forget?" to someone mid-retry is noise.
            ->where('status', RecoveryStatus::Pending)
            ->whereNull('reminder_sent_at')
            ->where('created_at', '<=', now()->subHours(self::REMIND_AFTER_HOURS))
            ->where('expires_at', '>', now()->addHours(self::MIN_REMAINING_HOURS))
            ->with(['user', 'paymentSession'])
            ->limit(200)
            ->get();

        foreach ($due as $recovery) {
            // Consent is re-checked at send time, never trusted from the
            // first email: suppression lists and preferences change, and the
            // gap here is a day wide.
            if (! $recovery->user || ! $recovery->customer_email) {
                $recovery->update(['reminder_sent_at' => now()]);

                continue;
            }

            if (EmailSuppression::where('email', strtolower($recovery->customer_email))->exists()
                || ! $preferences->isAllowed($recovery->user, CommunicationCategory::PaymentRecovery, CommunicationChannel::Email)) {
                // Stamped so the sweep does not reconsider it every run.
                $recovery->update(['reminder_sent_at' => now()]);

                continue;
            }

            // A customer who failed again since has already had a fresher
            // email about a fresher failure. Nudging them about the older one
            // stacks messages on the person least in the mood for them.
            $hasNewerRecovery = TransactionRecovery::where('id', '!=', $recovery->id)
                ->where('created_at', '>', $recovery->created_at)
                ->where(function ($query) use ($recovery) {
                    $query->where('customer_email', $recovery->customer_email);
                    if ($recovery->user_id) {
                        $query->orWhere('user_id', $recovery->user_id);
                    }
                })
                ->exists();

            if ($hasNewerRecovery) {
                $recovery->update(['reminder_sent_at' => now()]);

                continue;
            }

            $copy = RecoveryCopy::for($recovery->failure_reason_code);

            $communications->send(
                recipient: $recovery->user,
                category: CommunicationCategory::PaymentRecovery,
                title: RecoveryCopy::reminderSubject($recovery->failure_reason_code),
                message: $copy['guidance'],
                mailable: new PaymentRecoveryMail($recovery, isReminder: true),
                correlationId: 'payment_recovery_reminder_'.$recovery->id,
                overrideEmail: strtolower($recovery->customer_email) !== strtolower((string) $recovery->user->email)
                    ? $recovery->customer_email
                    : null,
                metadata: [
                    'recovery_id' => $recovery->id,
                    'payment_session_id' => $recovery->payment_session_id,
                    'failure_reason_code' => $recovery->failure_reason_code,
                    'touch' => 'reminder',
                ]
            );

            $recovery->update(['reminder_sent_at' => now()]);
        }

        if ($due->isNotEmpty()) {
            Log::info('Recovery reminders processed.', ['count' => $due->count()]);
        }
    }
}
