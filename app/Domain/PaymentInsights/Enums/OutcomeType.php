<?php

namespace App\Domain\PaymentInsights\Enums;

enum OutcomeType: string
{
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Confirmed = 'confirmed';
}
