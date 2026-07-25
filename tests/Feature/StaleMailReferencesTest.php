<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The July 2026 Communication migration moved every mailable out of App\Mail
 * into App\Domain\Communication\Mail\*. Several call sites kept stale imports
 * and fatally broke at runtime (trade submission, newsletter, rewards, admin
 * mail). This guard fails the build if the dead namespace ever reappears.
 */
class StaleMailReferencesTest extends TestCase
{
    public function test_no_code_references_the_removed_app_mail_namespace(): void
    {
        // Built by concatenation so this file doesn't match its own needle.
        $needle = 'App\\'.'Mail'.'\\';

        $finder = Finder::create()
            ->files()
            ->in([
                base_path('app'),
                base_path('resources/views'),
                base_path('routes'),
                base_path('database'),
                base_path('tests'),
            ])
            ->name('*.php');

        $offenders = [];
        foreach ($finder as $file) {
            if (str_contains($file->getContents(), $needle)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Stale App\\Mail references found - that namespace was removed in the Communication migration.'
        );
    }
}
