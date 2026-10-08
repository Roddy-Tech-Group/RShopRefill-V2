<?php

namespace App\Models;

use App\Domain\Payment\Support\PaymentRouteTable;
use Database\Factories\PaymentRouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One row of the payment switchboard: a payment method in one currency.
 *
 * @property int $id
 * @property string $currency
 * @property string $method
 * @property bool $is_offered
 * @property int $sort_order
 * @property list<string> $gateways the gateways allowed to take it, first choice first
 * @property string|null $note why it is switched off, when it is
 * @property int|null $updated_by
 */
class PaymentRoute extends Model
{
    /** @use HasFactory<PaymentRouteFactory> */
    use HasFactory;

    protected $fillable = [
        'currency',
        'method',
        'is_offered',
        'sort_order',
        'gateways',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_offered' => 'boolean',
            'sort_order' => 'integer',
            'gateways' => 'array',
        ];
    }

    /**
     * Every write reaches the next payment at once: the cached copy of the
     * switchboard is dropped whenever a row changes, and dropped again once the
     * surrounding transaction commits, so a payment that read the old rows in
     * between cannot leave them cached forever.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::forgetTable());
        static::deleted(fn () => self::forgetTable());
    }

    private static function forgetTable(): void
    {
        PaymentRouteTable::forget();
        DB::afterCommit(fn () => PaymentRouteTable::forget());
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
