<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One login that arrived from a different country than the one before it,
 * close enough in time to be worth noticing.
 *
 * A log, never an input. `geo:report` reads it; nothing else does.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $from_country_code
 * @property string $to_country_code
 * @property string|null $from_country_name
 * @property string|null $to_country_name
 * @property int $minutes_apart
 * @property int $window_hours
 * @property bool $challenged
 * @property bool $destination_proven
 * @property string|null $ip_address
 * @property string|null $device_uuid
 * @property Carbon $created_at
 */
class GeoVelocityEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'from_country_code',
        'to_country_code',
        'from_country_name',
        'to_country_name',
        'minutes_apart',
        'window_hours',
        'challenged',
        'destination_proven',
        'ip_address',
        'device_uuid',
    ];

    protected function casts(): array
    {
        return [
            'minutes_apart' => 'integer',
            'window_hours' => 'integer',
            'challenged' => 'boolean',
            'destination_proven' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The hop in a form a human reads, e.g. "CM to US in 23 min".
     */
    public function describeHop(): string
    {
        return sprintf(
            '%s to %s in %s',
            $this->from_country_code,
            $this->to_country_code,
            $this->minutes_apart < 60
                ? $this->minutes_apart.' min'
                : round($this->minutes_apart / 60, 1).' h',
        );
    }
}
