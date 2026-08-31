<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who a closed account belonged to, kept only for the statutory
 * anti-money-laundering period after closure. Written once by
 * AccountClosureService when an account with financial history is anonymised,
 * read only by compliance lookups, deleted by the retention sweep once
 * `retain_until` has passed. Never consulted at sign-in or sign-up.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $kyc_provider
 * @property string|null $kyc_reference
 * @property Carbon $closed_at
 * @property Carbon $retain_until
 */
class ClosedAccountIdentity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'country',
        'kyc_provider',
        'kyc_reference',
        'closed_at',
        'retain_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'closed_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
