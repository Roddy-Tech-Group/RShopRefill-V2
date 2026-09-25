<?php

namespace Tests\Feature\Kyc;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The migration that retires the manual KYC upload removes customers' stored ID
 * documents for good, so it must take exactly those files and that table, and
 * leave the accounts' verification and everything else on the disk alone.
 */
class LegacyKycSubmissionRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_15_210546_drop_legacy_kyc_submissions.php');
    }

    public function test_it_deletes_the_stored_documents_and_the_table_but_not_the_verification(): void
    {
        Storage::fake('local');
        $migration = $this->migration();
        $migration->down();

        $user = User::factory()->create(['kyc_status' => 'verified']);
        $paths = ["kyc/{$user->id}/front.jpg", "kyc/{$user->id}/back.jpg", "kyc/{$user->id}/selfie.jpg"];

        foreach ($paths as $path) {
            Storage::disk('local')->put($path, 'id-image');
        }
        Storage::disk('local')->put('exports/unrelated.pdf', 'keep me');

        DB::table('kyc_submissions')->insert([
            'user_id' => $user->id,
            'full_name' => 'Jane Doe',
            'document_type' => 'passport',
            'document_number' => '102076478',
            'document_front_path' => $paths[0],
            'document_back_path' => $paths[1],
            'selfie_path' => $paths[2],
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('kyc_submissions'));

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertFalse(Storage::disk('local')->exists('kyc'));
        Storage::disk('local')->assertExists('exports/unrelated.pdf');

        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_running_it_again_after_the_table_is_gone_is_harmless(): void
    {
        Storage::fake('local');

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('kyc_submissions'));
    }
}
