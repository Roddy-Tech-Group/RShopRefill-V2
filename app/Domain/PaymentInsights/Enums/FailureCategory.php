<?php

namespace App\Domain\PaymentInsights\Enums;

enum FailureCategory: string
{
    case Customer = 'customer';
    case Provider = 'provider';
    case Frontend = 'frontend';
    case System = 'system';
}
