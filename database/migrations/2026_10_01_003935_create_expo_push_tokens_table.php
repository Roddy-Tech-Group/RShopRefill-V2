<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a phone's push token lives.
 *
 * Its own table rather than a column on push_subscriptions, because that table is
 * strictly Web Push: endpoint, p256dh_key and auth_token are all NOT NULL and an
 * Expo token has no equivalent of any of them. Loosening three constraints on a
 * live table to accommodate a different transport would leave both halves
 * half-valid.
 *
 * The token is globally UNIQUE, which is the important decision here. A token
 * identifies one app installation on one physical phone, so it must belong to one
 * account at a time. Without that, a phone that is sold, or shared between a
 * customer and their spouse, would go on receiving the previous owner's order and
 * wallet notifications. Registering a token therefore takes it from whoever held it
 * before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expo_push_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Expo's own token, e.g. ExponentPushToken[xxxxxxxx]. Unique across the
            // table: see the note above about sold and shared phones.
            $table->string('token')->unique();

            // The same installation id the app sends in X-Installation-ID, so a
            // device's push token and its trusted-device row can be lined up, and so
            // re-registering from the same phone updates rather than duplicates.
            $table->string('installation_id')->index();

            $table->string('platform', 20)->nullable();
            $table->string('app_version', 40)->nullable();

            // Touched on every registration. For diagnostics and for knowing which
            // build a device is on, NOT for expiring tokens by age: a customer who
            // has not opened the app in six months is exactly who a notification
            // should reach, and their token is still perfectly valid.
            //
            // A token stops being valid when the app is UNINSTALLED, and the only
            // thing that knows is Expo, which answers DeviceNotRegistered when we
            // push to it. Deleting a row belongs in that response handler, when the
            // sending side is built.
            $table->dateTime('last_registered_at');

            $table->timestamps();

            // One token per device per account, which is what a re-registration
            // updates in place.
            $table->unique(['user_id', 'installation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expo_push_tokens');
    }
};
