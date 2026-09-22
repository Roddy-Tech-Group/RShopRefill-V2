<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
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
