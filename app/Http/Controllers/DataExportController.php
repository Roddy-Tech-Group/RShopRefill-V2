<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Auth\Services\DataExportService;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\DataExportedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Events\OtpRequested;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Jenssegers\Agent\Agent;

/**
 * Self-serve copy of a customer's personal data, as a branded PDF.
 *
 * The gate is a one-time code to the account's email, not the password: a
 * password can be known by someone else in the household or on a shared
 * device, and this file is a full profile plus transaction history in one
 * place. The code follows the device-verification rules (10-minute life,
 * five wrong guesses burn it, escalating resend cooldown). Every download is
 * audited with the time, so a data-access request has a recorded fulfilment.
 */
class DataExportController extends Controller
{
    public function __construct(private readonly CommunicationManager $communications) {}

    private const DOWNLOADS_PER_DAY = 3;

    private const OTP_CONTEXT = 'data_export';

    /** @var array<int, int> resend cooldown in seconds, by request count */
    private const RESEND_DELAYS = [1 => 60, 2 => 180, 3 => 300, 4 => 1800, 5 => 3600];

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Refuse before any code is emailed: sending a code and then rejecting
        // the download after the customer has typed it would be a wasted trip.
        if ($this->downloadsExhausted($user->id)) {
            return $this->limitReached();
        }

        if (! Cache::has($this->key('otp', $user->id))) {
            $sent = $this->issueCode($request);

            if ($sent !== null) {
                return $sent;
            }
        }

        return view('dashboard.data-export', [
            'maskedEmail' => $this->mask($user->email),
            'cooldown' => max(0, (int) Cache::get($this->key('throttle', $user->id), 0) - time()),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        if ($this->downloadsExhausted($request->user()->id)) {
            return $this->limitReached();
        }

        return $this->issueCode($request) ?? back()->with('status', 'A new code has been sent to your email.');
    }

    public function verify(Request $request, AuditLogService $audit): RedirectResponse
    {
        $request->validate(['otp' => ['required', 'string', 'size:6']]);

        $user = $request->user();
        $stored = Cache::get($this->key('otp', $user->id));
        $failsKey = $this->key('fails', $user->id);

        if (! $stored || ! hash_equals((string) $stored, (string) $request->input('otp'))) {
            $fails = (int) Cache::get($failsKey, 0) + 1;

            if ($fails >= 5) {
                Cache::forget($this->key('otp', $user->id));
                Cache::forget($failsKey);

                return back()->withErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);
            }

            Cache::put($failsKey, $fails, now()->addMinutes(10));

            return back()->withErrors(['otp' => 'Invalid or expired code.']);
        }

        // Already refused before the code was sent; this only guards a race
        // between two tabs.
        if ($this->downloadsExhausted($user->id)) {
            return $this->limitReached();
        }

        foreach (['otp', 'fails', 'throttle', 'attempts'] as $suffix) {
            Cache::forget($this->key($suffix, $user->id));
        }

        RateLimiter::hit('data-export:'.$user->id, 86400);

        // Audit at verification: the download must be attributable even if
        // the browser then abandons it.
        $audit->log(action: 'user.data_exported', model: $user, metadata: ['ip' => $request->ip()]);

        // Security notice to the account email (never the file): if this was
        // not the customer, they find out now rather than never.
        $agent = new Agent;
        $agent->setUserAgent((string) $request->userAgent());
        $device = trim(($agent->browser() ?: 'Unknown browser').' on '.($agent->platform() ?: 'unknown device'));

        $this->communications->send(
            recipient: $user,
            category: CommunicationCategory::SecurityIncident,
            title: 'A copy of your data was downloaded',
            message: 'A copy of your personal data was downloaded from your account on '.now()->format('j F Y \a\t H:i').' UTC ('.$device.'). If this was not you, change your password.',
            mailable: new DataExportedMail($user->name, now()->format('l, j F Y \a\t H:i').' UTC', $device),
            correlationId: 'data_exported_'.$user->id.'_'.now()->timestamp,
        );

        // One short-lived ticket for the file itself, so the customer lands
        // back on settings with a confirmation while the PDF starts on its own.
        Cache::put($this->key('ready', $user->id), true, now()->addMinutes(2));

        return redirect()->route('dashboard.profile')
            ->with('status', 'Verified. Your data is downloading.')
            ->with('data_export_ready', true);
    }

    /**
     * The PDF itself, redeemable once within two minutes of verification.
     */
    public function file(Request $request, DataExportService $export): Response|RedirectResponse
    {
        $user = $request->user();

        if (! Cache::pull($this->key('ready', $user->id))) {
            return redirect()->route('privacy.export');
        }

        $pdf = Pdf::loadView('dashboard.data-export-pdf', ['data' => $export->build($user)]);

        return $pdf->download(sprintf('rshoprefill-my-data-%s.pdf', now()->format('Y-m-d')));
    }

    /**
     * Generate and email a fresh code, or return the redirect explaining why
     * not (cooldown, too many requests). Null means the code went out.
     */
    private function issueCode(Request $request): ?RedirectResponse
    {
        $user = $request->user();
        $throttleKey = $this->key('throttle', $user->id);
        $attemptsKey = $this->key('attempts', $user->id);

        $readyAt = (int) Cache::get($throttleKey, 0);

        if ($readyAt - time() > 3) {
            return back()->withErrors(['resend' => 'Please wait before requesting another code.']);
        }

        $attempts = (int) Cache::get($attemptsKey, 0) + 1;

        if ($attempts > count(self::RESEND_DELAYS)) {
            return back()->withErrors(['resend' => 'You have reached the limit for new codes. Please try again later.']);
        }

        $delay = self::RESEND_DELAYS[$attempts];
        $otp = sprintf('%06d', random_int(0, 999999));

        Cache::put($this->key('otp', $user->id), $otp, now()->addMinutes(10));
        Cache::put($throttleKey, now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
        Cache::put($attemptsKey, $attempts, now()->addHours(24));
        Cache::forget($this->key('fails', $user->id));

        OtpRequested::dispatch($user, $otp, self::OTP_CONTEXT);

        return null;
    }

    private function downloadsExhausted(int $userId): bool
    {
        return RateLimiter::tooManyAttempts('data-export:'.$userId, self::DOWNLOADS_PER_DAY);
    }

    /**
     * Back to settings with a toast; the layout dismisses it after a few seconds.
     */
    private function limitReached(): RedirectResponse
    {
        return redirect()->route('dashboard.profile')
            ->with('error', 'You have reached the daily limit for data downloads. Please try again later.');
    }

    private function key(string $suffix, int $userId): string
    {
        return 'data_export_'.$suffix.'_'.$userId;
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(2, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
