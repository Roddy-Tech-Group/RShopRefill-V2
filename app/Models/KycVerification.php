<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A provider-run identity-verification attempt (Smile ID web session). Created
 * pending when the session is minted; the provider's webhook (or an admin
 * job-status sync) resolves it to approved/rejected. `reference` is the
 * provider job id, which is also the key for reconciling a stuck job against
 * the provider's job-status API.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $reference
 * @property string|null $product
 * @property string $status
 * @property string|null $result_code
 * @property string|null $result_text
 * @property Carbon|null $decided_at
 * @property string|null $legal_name
 * @property string|null $date_of_birth
 * @property string|null $id_type
 * @property string|null $id_country
 * @property Carbon|null $id_expires_on
 * @property Carbon|null $name_offer_declined_at
 */
class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'reference',
        'product',
        'status',
        'result_code',
        'result_text',
        'decided_at',
        'legal_name',
        'date_of_birth',
        'id_type',
        'id_country',
        'id_expires_on',
        'name_offer_declined_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'legal_name' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'id_expires_on' => 'date',
            'name_offer_declined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Still without a verdict: pending, or abandoned because a newer attempt
     * replaced it. An abandoned attempt may still have been submitted, so a
     * late verdict from the provider must be able to land on it.
     */
    public function isAwaitingVerdict(): bool
    {
        return in_array($this->status, ['pending', 'abandoned'], true);
    }

    /**
     * Whether this attempt kept who the document said the customer is.
     */
    public function hasLegalIdentity(): bool
    {
        return $this->legal_name !== null || $this->date_of_birth !== null;
    }

    /**
     * The legal name written the way a person writes it: Smile reads most
     * documents back in capitals ("JOE DOE"), which is not how a customer
     * would want their name shown.
     */
    public function legalNameForDisplay(): ?string
    {
        return $this->legal_name !== null ? Str::of($this->legal_name)->lower()->title()->squish()->toString() : null;
    }

    /**
     * Whether a display name is a different name from the verified one,
     * ignoring case and spacing. A nickname is allowed; admins just see it.
     */
    public function legalNameDiffersFrom(?string $displayName): bool
    {
        if ($this->legal_name === null) {
            return false;
        }

        $normalise = fn (?string $name): string => Str::of((string) $name)->ascii()->lower()->squish()->toString();

        return $normalise($this->legal_name) !== $normalise($displayName);
    }

    public function dateOfBirth(): ?Carbon
    {
        return $this->date_of_birth !== null ? Carbon::parse($this->date_of_birth) : null;
    }

    /**
     * The issuing country's name, falling back to its code.
     */
    public function idCountryName(): ?string
    {
        if ($this->id_country === null) {
            return null;
        }

        $name = array_search($this->id_country, array_map('strtoupper', (array) config('countries.codes', [])), true);

        return is_string($name) ? $name : $this->id_country;
    }

    /**
     * Human label for the document type, e.g. PASSPORT -> Passport,
     * NATIONAL_ID -> National ID.
     */
    public function idTypeLabel(): ?string
    {
        if ($this->id_type === null) {
            return null;
        }

        return match ($this->id_type) {
            'NATIONAL_ID', 'NATIONAL_ID_NO_PHOTO', 'NIN', 'NIN_V2', 'NIN_SLIP' => 'National ID',
            'DRIVERS_LICENSE' => "Driver's licence",
            'VOTER_ID' => "Voter's card",
            'RESIDENT_ID' => 'Resident ID',
            default => Str::of($this->id_type)->replace('_', ' ')->lower()->ucfirst()->toString(),
        };
    }

    /**
     * Human label for the Smile product this attempt ran.
     */
    public function productLabel(): string
    {
        return match ($this->product) {
            'biometric_kyc' => 'Biometric KYC',
            'doc_verification' => 'Document Verification',
            default => $this->product ?: 'Verification',
        };
    }
}
