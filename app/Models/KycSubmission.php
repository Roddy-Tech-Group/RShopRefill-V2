<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's identity-verification submission. Document paths reference the
 * private `local` disk and must only ever be served through an admin-gated route.
 *
 * @property int $id
 * @property int $user_id
 * @property string $full_name
 * @property Carbon|null $date_of_birth
 * @property string|null $country
 * @property string $document_type
 * @property string|null $document_number
 * @property string $document_front_path
 * @property string|null $document_back_path
 * @property string|null $selfie_path
 * @property string $status
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class KycSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'date_of_birth',
        'country',
        'document_type',
        'document_number',
        'document_front_path',
        'document_back_path',
        'selfie_path',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
            'document_number' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }
}
