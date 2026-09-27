<?php

namespace Database\Seeders;

use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bulk synthetic customers for load testing.
 *
 * Names and email addresses are realistic on purpose: a load test against rows
 * that all read "Demo Customer 1" exercises index selectivity and search
 * behaviour that real data never would, so the numbers it produces are not the
 * numbers you will see in production.
 *
 * REMOVAL IS BY ID RANGE, NOT BY PATTERN.
 * Because the rows look real, nothing in them identifies them as synthetic.
 * That is the point, and it is also the danger: a DELETE written by eye later
 * would either miss them or take real customers with them. So the seeder
 * records the exact id range it created to storage/app/demo-users-range.json
 * and prints the precise removal statement when it finishes. Keep that file.
 * Without it these rows are indistinguishable from genuine sign-ups and there
 * is no safe way to undo this.
 *
 * RUNNING OUTSIDE LOCAL IS DELIBERATE, NEVER ACCIDENTAL.
 * Pass --force AND set DEMO_SEED_ALLOW_PRODUCTION=true. Two gates, because on
 * production these rows count toward the homepage's "Customers worldwide"
 * figure, which is shown to real visitors as fact. For as long as they exist,
 * that number is wrong.
 *
 * If you want the load test WITHOUT the public claim being wrong, exclude
 * synthetic ids from the counter in
 * resources/views/components/home/customer-counts.blade.php rather than
 * accepting the false figure for the duration.
 */
class DemoUsersSeeder extends Seeder
{
    /**
     * The figure the homepage should read, NOT a number of rows to insert.
     *
     * This is a target rather than a count because the thing being aimed at is
     * public: the counts box shows every non-guest account, real ones included.
     * Seeding a flat 5,160 rows would put the site at 5,160 plus however many
     * real customers it already has, which is a different number on every
     * environment and never the one that was asked for. So the seeder counts
     * what is there and fills the gap.
     */
    private const TARGET_TOTAL = 5160;

    private const CHUNK = 500;

    private const RANGE_FILE = 'demo-users-range.json';

    public function run(): void
    {
        if (! app()->environment('local') && ! env('DEMO_SEED_ALLOW_PRODUCTION', false)) {
            $this->command->error('Refusing to seed outside local.');
            $this->command->line('These rows are not customers, and on production they will inflate the');
            $this->command->line('public customer figure for as long as they exist.');
            $this->command->line('To proceed anyway: DEMO_SEED_ALLOW_PRODUCTION=true php artisan db:seed --class=DemoUsersSeeder --force');

            return;
        }

        $existing = (int) DB::table('users')->where('is_guest', false)->count();
        $count = self::TARGET_TOTAL - $existing;

        if ($count <= 0) {
            $this->command->info("Nothing to do: {$existing} non-guest accounts already meet the target of ".self::TARGET_TOTAL.'.');

            return;
        }

        $this->command->line("{$existing} real accounts present, creating {$count} to reach ".self::TARGET_TOTAL.'.');

        $faker = Factory::create();
        $password = Hash::make(Str::random(32));
        $now = now();

        // Ordinary consumer domains, so the address distribution matches what
        // the indexes will actually see.
        $domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'proton.me'];

        $firstId = null;
        $made = 0;
        $bar = $this->command->getOutput()->createProgressBar($count);

        foreach (array_chunk(range(1, $count), self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $i) {
                $first = $faker->firstName();
                $last = $faker->lastName();
                $handle = Str::lower(Str::ascii($first).'.'.Str::ascii($last)).random_int(1, 9999);

                $rows[] = [
                    'name' => $first.' '.$last,
                    'email' => $handle.'@'.$domains[array_rand($domains)],
                    'password' => $password,
                    'is_guest' => false,
                    'email_verified_at' => $now,
                    'profile_completed_at' => $now,
                    // Spread over two years so date-bucketed queries and charts
                    // see a realistic distribution rather than one spike.
                    'created_at' => $now->copy()->subDays(random_int(0, 730))->subMinutes(random_int(0, 1439)),
                    'updated_at' => $now,
                ];
            }

            DB::table('users')->insert($rows);
            $firstId ??= (int) DB::getPdo()->lastInsertId();
            $made += count($rows);
            $bar->advance(count($rows));
        }

        $lastId = (int) DB::table('users')->max('id');
        $bar->finish();
        $this->command->newLine(2);

        // Written to disk, not just printed: the terminal scrollback is not a
        // safe place to keep the only record of which rows are synthetic.
        $payload = [
            'seeded_at' => $now->toIso8601String(),
            'environment' => app()->environment(),
            'count' => $made,
            'first_id' => $firstId,
            'last_id' => $lastId,
        ];
        Storage::disk('local')->put(self::RANGE_FILE, json_encode($payload, JSON_PRETTY_PRINT));

        $this->command->info("Created {$made} synthetic users, ids {$firstId}..{$lastId}.");
        $this->command->line('The homepage now reads '.number_format(self::TARGET_TOTAL).'+ customers.');
        $this->command->newLine();
        $this->command->warn('These rows look like real customers. The ONLY safe way to remove them is by id range:');
        $this->command->line("  php artisan tinker --execute=\"DB::table('users')->whereBetween('id', [{$firstId}, {$lastId}])->delete();\"");
        $this->command->newLine();
        $this->command->line('Range also saved to storage/app/'.self::RANGE_FILE.' - keep it until they are gone.');
    }
}
