<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the identity document said, kept from an approved verification. Smile
 * returns the holder's name, date of birth and document details with every
 * pass, and we used to discard all of it, so a customer verified under a
 * nickname stayed known to us only by the nickname.
 *
 * Name and date of birth are encrypted at rest (text, for the ciphertext). The
 * ID number is deliberately not stored: Smile holds the document under our job
 * reference, and nothing here needs the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->text('legal_name')->nullable()->after('result_text');
            $table->text('date_of_birth')->nullable()->after('legal_name');
            $table->string('id_type', 40)->nullable()->after('date_of_birth');
            // The country that issued the document, not where the customer lives.
            $table->string('id_country', 2)->nullable()->after('id_type');
            $table->date('id_expires_on')->nullable()->after('id_country');
            // The customer chose to keep their own display name over this one;
            // dateTime, not timestamp, to avoid MySQL's implicit ON UPDATE.
            $table->dateTime('name_offer_declined_at')->nullable()->after('id_expires_on');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'date_of_birth', 'id_type', 'id_country', 'id_expires_on', 'name_offer_declined_at']);
        });
    }
};
