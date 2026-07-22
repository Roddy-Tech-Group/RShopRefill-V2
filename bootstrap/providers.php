<?php

use App\Domain\PaymentInsights\Providers\PaymentInsightsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CommunicationServiceProvider;
use App\Providers\FolioServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    CommunicationServiceProvider::class,
    FolioServiceProvider::class,
    HorizonServiceProvider::class,
    VoltServiceProvider::class,
    PaymentInsightsServiceProvider::class,
];
