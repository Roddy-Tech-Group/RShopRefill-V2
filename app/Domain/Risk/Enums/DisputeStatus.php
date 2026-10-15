<?php

namespace App\Domain\Risk\Enums;

/**
 * Where a dispute stands for us, whatever words the gateway uses. Open covers
 * everything still undecided, including one we have answered and the bank is
 * weighing. Lost covers a dispute we accepted, one accepted for us when the
 * deadline passed, and a partial loss: in each the money went back.
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }
}
