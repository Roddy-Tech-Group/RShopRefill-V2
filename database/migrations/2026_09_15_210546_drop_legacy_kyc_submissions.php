<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the retired manual KYC document upload: the stored ID images and the
 * kyc_submissions table. Identity is only ever verified by the provider now,
 * and holding ID documents nothing uses is liability without purpose.
 *
 * Production held two approved submissions (users 5 and 17, June 2026) with
 * six files under the private disk's kyc/ folder. Their document numbers were
 * stored in plain text under an encrypted cast, which is why their admin
 * customer pages returned a 500. Account verification lives on users.kyc_status
 * and is untouched here.
 *
 * Files are deleted by the paths the rows recorded, then any per-customer
 * folder left empty. The rollback recreates an empty table only: deleted
 * documents cannot come back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kyc_submissions')) {
            return;
        }

        $disk = Storage::disk('local');

        $paths = DB::table('kyc_submissions')
            ->get(['document_front_path', 'document_back_path', 'selfie_path'])
            ->flatMap(fn ($row) => [$row->document_front_path, $row->document_back_path, $row->selfie_path])
            ->filter(fn ($path) => is_string($path) && str_starts_with($path, 'kyc/'))
            ->unique()
            ->values();

        $disk->delete($paths->all());

        foreach ($disk->directories('kyc') as $directory) {
            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }

        if ($disk->exists('kyc') && $disk->allFiles('kyc') === []) {
            $disk->deleteDirectory('kyc');
        }

        Log::info('kyc.legacy_submissions_removed', [
            'rows' => DB::table('kyc_submissions')->count(),
            'files_deleted' => $paths->count(),
            'files_left_in_kyc_folder' => $disk->exists('kyc') ? count($disk->allFiles('kyc')) : 0,
        ]);

        Schema::drop('kyc_submissions');
    }

    public function down(): void
    {
        if (Schema::hasTable('kyc_submissions')) {
            return;
        }

        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->date('date_of_birth')->nullable();
            $table->string('country')->nullable();
            $table->string('document_type');
            $table->text('document_number')->nullable();
            $table->string('document_front_path');
            $table->string('document_back_path')->nullable();
            $table->string('selfie_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }
};
