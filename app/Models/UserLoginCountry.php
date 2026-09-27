<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One country an account has signed in from.
 *
 * `last_seen_at` is what the login country check compares against;
 * `proven_at` is set once the customer has demonstrated they reach this
 * country, which stops the check asking about it again until the proof expires.
 *
 * @property int $id
 * @property int $user_id
 * @property string $country_code
 * @property string|null $country_name
 * @property Carbon $last_seen_at
 * @property Carbon|null $proven_at
 * @property string|null $last_ip
 */
class UserLoginCountry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'country_code',
        'country_name',
        'last_seen_at',
        'proven_at',
        'last_ip',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'proven_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the customer's proof for this country still stands.
     *
     * Proof expires so an account cannot build a permanent list of countries
     * that silences the check for the life of the account: an attacker who
     * passed a code once should not own that country forever.
     */
    public function isProven(int $expiryDays): bool
    {
        if ($this->proven_at === null) {
            return false;
        }

        return $expiryDays <= 0
            || $this->proven_at->greaterThanOrEqualTo(now()->subDays($expiryDays));
    }
}
