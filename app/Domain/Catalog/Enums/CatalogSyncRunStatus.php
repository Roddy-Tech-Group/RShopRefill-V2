<?php

namespace App\Domain\Catalog\Enums;

enum CatalogSyncRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
