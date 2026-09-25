<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * The customers headline on the public pages can be overridden from the admin,
 * and counts real accounts when the override is blank.
 *
 * `trust.customers_served` already existed for exactly this figure and nothing
 * read it, so it becomes the override rather than a second key competing with
 * it. It was seeded with a placeholder, which would now be published the moment
 * the code starts reading it, so it is emptied here: blank is the setting's
 * "work it out yourself" state, and that is what the site should do until
 * someone deliberately types a number.
 */
return new class extends Migration
{
    private const KEY = 'trust.customers_served';

    private const DESCRIPTION = 'Customers headline shown on the home and partner pages. Leave blank to count real registered accounts, or enter a number to publish that instead.';

    public function up(): void
    {
        $existing = SiteSetting::query()->where('key', self::KEY)->first();

        // Only the seeded placeholder is cleared. A number typed by an admin
        // since is their decision, and this migration must not overrule it.
        $keep = $existing && ! in_array(trim((string) $existing->value), ['', '50000'], true);

        SiteSetting::put(
            self::KEY,
            $keep ? $existing->value : '',
            'trust',
            self::DESCRIPTION,
        );
    }

    public function down(): void
    {
        SiteSetting::put(self::KEY, '50000', 'trust', 'Customers served headline number. Rounds in the UI.');
    }
};
