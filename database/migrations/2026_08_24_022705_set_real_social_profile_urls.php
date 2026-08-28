<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Points the social settings at the real Roddy Technologies profiles.
 *
 * LinkedIn and Discord were seeded blank by 2026_05_30_181000 and documented
 * "Hidden when blank", so their icons never rendered. X was seeded with a
 * placeholder handle that does not match the live account.
 *
 * The X row is only rewritten when it still holds that placeholder, so an
 * admin edit made in the meantime survives. LinkedIn and Discord are only
 * filled when blank, matching 2026_05_30_180000's "never clobber" rule.
 */
return new class extends Migration
{
    private const PLACEHOLDER_X = 'https://x.com/rshoprefills';

    private const REAL_X = 'https://x.com/roddytech237';

    /** @var array<string, string> */
    private const FILL_IF_BLANK = [
        'social.linkedin' => 'https://www.linkedin.com/company/roddy-technologies/',
        'social.discord' => 'https://discord.gg/nppG3GAt9v',
    ];

    public function up(): void
    {
        foreach (self::FILL_IF_BLANK as $key => $url) {
            $current = trim((string) SiteSetting::query()->where('key', $key)->value('value'));

            if ($current === '') {
                SiteSetting::put($key, $url, 'social', $this->descriptionFor($key));
            }
        }

        $currentX = trim((string) SiteSetting::query()->where('key', 'social.x')->value('value'));

        if ($currentX === '' || $currentX === self::PLACEHOLDER_X) {
            SiteSetting::put('social.x', self::REAL_X, 'social', 'Public X (Twitter) profile URL.');
        }
    }

    public function down(): void
    {
        // Blank the two that were empty before, and hand X back its placeholder.
        foreach (array_keys(self::FILL_IF_BLANK) as $key) {
            SiteSetting::put($key, '', 'social', $this->descriptionFor($key));
        }

        SiteSetting::put('social.x', self::PLACEHOLDER_X, 'social', 'Public X (Twitter) profile URL.');
    }

    private function descriptionFor(string $key): string
    {
        return $key === 'social.linkedin'
            ? 'LinkedIn company page URL. Hidden when blank.'
            : 'Discord invite URL. Hidden when blank.';
    }
};
