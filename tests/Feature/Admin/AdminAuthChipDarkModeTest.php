<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The chip above each admin sign-in step (Administrator, Security Check, the
 * security question) stays readable in dark mode.
 *
 * Its pale tint resolves to a light grey that the dark theme leaves alone,
 * while the same theme lifts black text to near-white, so the label was
 * light-on-light. In dark the chip takes the translucent white surface the
 * dashboard uses for its chips, and the lifted text reads on it.
 */
class AdminAuthChipDarkModeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function chips(): array
    {
        return [
            'login' => ['views/admin/auth/login.blade.php'],
            'two-factor challenge' => ['views/admin/auth/2fa-challenge.blade.php'],
            'security question' => ['views/admin/auth/security-question.blade.php'],
        ];
    }

    #[DataProvider('chips')]
    public function test_the_chip_has_a_dark_surface(string $view): void
    {
        $source = File::get(resource_path($view));

        $this->assertMatchesRegularExpression(
            '/<span class="[^"]*uppercase tracking-wider text-black dark:bg-white\/10"/',
            $source,
            "the chip in {$view} needs a dark-mode surface its lifted text can read on"
        );
    }
}
