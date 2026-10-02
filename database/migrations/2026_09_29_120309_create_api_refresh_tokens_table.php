<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh tokens for the mobile API.
 *
 * Deliberately NOT stored as Sanctum tokens, for two reasons.
 *
 * The safety one: if refresh tokens lived in personal_access_tokens, then any
 * /v2 route protected by `auth:sanctum` alone would accept one. Separating them
 * by ability would work only as long as every future route remembers to declare
 * its ability, and the failure is silent. In their own table a refresh token
 * cannot authenticate an API call at all, whatever anyone forgets.
 *
 * The lifecycle one: rotation needs `consumed_at`, theft detection needs
 * `family_id`, and revocation needs `revoked_at`. Sanctum's table has none of
 * those and bending a vendor table to carry them ages badly.
 *
 * Every date column here is dateTime(), not timestamp(). MariaDB gives the first
 * NOT NULL TIMESTAMP column in a table an implicit ON UPDATE CURRENT_TIMESTAMP,
 * which would silently move `expires_at` forward on every save and make these
 * tokens immortal. dateTime() is immune regardless of column order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_refresh_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // One device's chain of refresh tokens. Rotation issues a successor
            // in the same family, so presenting an already-used token proves
            // someone has a copy they should not have and the whole family
            // (that device's sign-in) can be cut loose at once.
            $table->uuid('family_id')->index();

            // sha256 of the token we handed out. The plaintext is never stored,
            // so a database dump does not hand over live sessions.
            $table->char('token_hash', 64)->unique();

            // The access token issued alongside this one, so signing out kills
            // both halves and rotation can retire the previous access token
            // immediately instead of leaving it valid for its remaining hour.
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained()->nullOnDelete();

            // The phone, as the app reports it in X-Installation-ID. Lets the
            // security centre name the device a token belongs to.
            $table->string('installation_id')->nullable()->index();

            $table->dateTime('expires_at')->index();
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();

            // How every live-token lookup reads: whose, and still valid.
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_refresh_tokens');
    }
};
