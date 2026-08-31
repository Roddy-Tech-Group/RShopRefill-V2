<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial and identity records must outlive the customer relationship (five
 * to seven years, per the privacy policy and MLPPA). Until now every one of
 * these tables cascaded on users.id, so a single hard delete of a user row
 * silently destroyed the ledger. The database now refuses instead. Account
 * closure anonymises the user row rather than deleting it, so nothing in the
 * application relies on the old cascade.
 *
 * MySQL only: the test database is SQLite, where foreign keys are rebuilt by
 * copying the table, and the model-level guard on User covers that path.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'wallets',
        'wallet_transactions',
        'orders',
        'payments',
        'payment_attempts',
        'wallet_fundings',
        'kyc_submissions',
        'kyc_verifications',
        'rcoin_withdrawals',
    ];

    public function up(): void
    {
        $this->rebuildForeignKeys(restrict: true);
    }

    public function down(): void
    {
        $this->rebuildForeignKeys(restrict: false);
    }

    private function rebuildForeignKeys(bool $restrict): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'user_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table($name, function (Blueprint $table) use ($restrict) {
                $foreign = $table->foreign('user_id')->references('id')->on('users');

                $restrict ? $foreign->restrictOnDelete() : $foreign->cascadeOnDelete();
            });
        }
    }
};
