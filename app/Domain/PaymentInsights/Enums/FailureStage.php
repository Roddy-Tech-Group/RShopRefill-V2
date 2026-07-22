<?php

namespace App\Domain\PaymentInsights\Enums;

enum FailureStage: string
{
    case Initialization = 'initialization';
    case Authentication = 'authentication';
    case Processing = 'processing';
    case Confirmation = 'confirmation';
}
