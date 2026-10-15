<?php

namespace App\Domain\Payment\Console;

use App\Domain\Payment\Support\FlutterwaveHttp;
use App\Domain\Payment\Support\MockMode;
use App\Domain\Risk\DTOs\DisputeNotice;
use App\Domain\Risk\Services\DisputeNoticeParser;
use App\Domain\Risk\Services\DisputeRecorder;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Every dispute the gateways hold against us, read from their chargeback
 * lists, so a webhook that never came (Flutterwave only sends chargeback
 * webhooks once asked to) never hides one. Each row goes through the same
 * DisputeRecorder as a webhook: new ones page and label the customer, known
 * ones only update. Then any open dispute close to its deadline pages again.
 *
 * Flutterwave is read whenever its keys are real. Kora only once its gateway
 * switch is on. Paystack has no list here; its dispute webhooks cover it and
 * repeat every few hours until a dispute is resolved.
 */
class SyncDisputesCommand extends Command
{
    protected $signature = 'disputes:sync
        {--days=30 : Read disputes raised in the last this many days}
        {--deadline-hours=6 : Page again for open disputes due within this many hours}';

    protected $description = 'Read each gateway\'s chargeback list into payment_disputes and page on new disputes and near deadlines';

    private const FLUTTERWAVE_URL = 'https://api.flutterwave.com/v3/chargebacks';

    private const KORAPAY_URL = 'https://api.korapay.com/api/v1/chargebacks';

    private const MAX_PAGES = 20;

    public function handle(DisputeNoticeParser $parser, DisputeRecorder $recorder): int
    {
        $days = max(1, (int) $this->option('days'));
        $unreached = [];
        $seen = 0;

        foreach (['flutterwave', 'korapay'] as $gateway) {
            if (! $this->shouldRead($gateway)) {
                $this->line("{$gateway}: skipped (not live).");

                continue;
            }

            $notices = $gateway === 'flutterwave'
                ? $this->readFlutterwave($parser, $days)
                : $this->readKorapay($parser, $days);

            if ($notices === null) {
                $unreached[] = $gateway;
                $this->warn("{$gateway}: chargeback list could not be read.");

                continue;
            }

            foreach ($notices as $notice) {
                $recorder->record($notice, 'sync');
            }

            $seen += count($notices);
            $this->line("{$gateway}: ".count($notices).' dispute(s) in the last '.$days.' day(s).');
        }

        $paged = $recorder->alertApproachingDeadlines(max(1, (int) $this->option('deadline-hours')));

        $this->info("Read {$seen} dispute(s); {$paged} deadline alert(s) sent.");

        // Unreachable is reported to the scheduler's monitor, not paged: the
        // next hour tries again, and a run that read nothing decided nothing.
        return $unreached === [] ? self::SUCCESS : self::FAILURE;
    }

    private function shouldRead(string $gateway): bool
    {
        $key = (string) config("services.{$gateway}.secret_key");

        if ($key === '' || MockMode::forKey($key)) {
            return false;
        }

        return $gateway === 'flutterwave' || FeatureFlag::gatewayEnabled($gateway);
    }

    /** @return list<DisputeNotice>|null */
    private function readFlutterwave(DisputeNoticeParser $parser, int $days): ?array
    {
        $notices = [];
        $page = 1;

        do {
            $response = FlutterwaveHttp::readClient((string) config('services.flutterwave.secret_key'))->get(self::FLUTTERWAVE_URL, [
                'from' => now()->subDays($days)->toDateString(),
                'to' => now()->addDay()->toDateString(),
                'page' => $page,
            ]);

            if (! $response->successful() || $response->json('status') !== 'success') {
                return null;
            }

            foreach ((array) $response->json('data', []) as $row) {
                if (is_array($row) && ($notice = $parser->flutterwave($row)) !== null) {
                    $notices[] = $notice;
                }
            }

            $totalPages = (int) $response->json('meta.page_info.total_pages', 1);
            $page++;
        } while ($page <= min($totalPages, self::MAX_PAGES));

        return $notices;
    }

    /** @return list<DisputeNotice>|null */
    private function readKorapay(DisputeNoticeParser $parser, int $days): ?array
    {
        $response = Http::withToken((string) config('services.korapay.secret_key'))
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->get(self::KORAPAY_URL, [
                'date_from' => now()->subDays($days)->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'limit' => 100,
            ]);

        if (! $response->successful() || $response->json('status') !== true) {
            return null;
        }

        $notices = [];

        foreach ((array) $response->json('data.data', []) as $row) {
            if (is_array($row) && ($notice = $parser->korapay($row)) !== null) {
                $notices[] = $notice;
            }
        }

        return $notices;
    }
}
