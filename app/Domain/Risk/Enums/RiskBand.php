<?php

namespace App\Domain\Risk\Enums;

/**
 * The score band a risk assessment fell into. Purely a bucketing of the numeric
 * score (cutoffs in config/risk.php); the band -> outcome map turns it into a
 * decision. Kept as an enum so surfaces (admin queue, reports) have a stable,
 * typed vocabulary rather than loose strings.
 */
enum RiskBand: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';

    /**
     * Resolve the band for a score from the config cutoffs (lower-inclusive,
     * evaluated high to low). Falls back to LOW when nothing matches.
     *
     * @param  array<string, int>  $cutoffs
     */
    public static function fromScore(int $score, array $cutoffs): self
    {
        arsort($cutoffs);

        foreach ($cutoffs as $band => $min) {
            if ($score >= $min) {
                return self::from($band);
            }
        }

        return self::Low;
    }
}
