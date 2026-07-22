<?php

namespace App\Models;

use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\FailureStage;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Enums\ProductLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOutcome extends Model
{
    protected $guarded = [];

    protected $casts = [
        'product_line' => ProductLine::class,
        'outcome' => OutcomeType::class,
        'failure_stage' => FailureStage::class,
        'failure_category' => FailureCategory::class,
        'metadata' => 'array',
    ];

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
