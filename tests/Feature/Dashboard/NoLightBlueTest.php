<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * No light blue anywhere in the app, and no hover popup for the Shop services
 * when the dashboard sidebar is collapsed.
 */
class NoLightBlueTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    /** Tailwind's pale blues are redefined as the dashboard's neutral greys. */
    public function test_the_pale_blue_theme_tokens_are_neutral_greys(): void
    {
        preg_match('/@theme \{(.*?)\n\}/s', $this->css(), $m);
        $this->assertNotEmpty($m, 'Could not read the @theme block.');

        foreach ([
            '--color-blue-50: #f4f6f5;',
            '--color-blue-100: #e8ecea;',
            '--color-blue-200: #dfe3e1;',
            '--color-blue-300: #c9d0cd;',
            '--color-blue-400: #a3aaa7;',
            '--color-sky-100: #e8ecea;',
            '--color-indigo-50: #f4f6f5;',
            '--color-brand-50: #f4f6f5;',
        ] as $token) {
            $this->assertStringContainsString($token, $m[1]);
        }
    }

    /** The literal pale blue hexes are gone from the interface, emails and PDFs. */
    public function test_no_literal_pale_blue_in_ui_emails_or_pdfs(): void
    {
        $files = [
            'css/app.css',
            'js/app.js',
            'views/components/layouts/dashboard.blade.php',
            'views/components/layouts/admin.blade.php',
            'views/components/pull-to-refresh.blade.php',
            'views/components/emails/layout.blade.php',
            'views/components/emails/panel.blade.php',
            'views/emails/admin/direct-message.blade.php',
            'views/dashboard/data-export-pdf.blade.php',
            'views/admin/reports/order-report-pdf.blade.php',
            'views/admin/reports/transaction-report-pdf.blade.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(resource_path($file));

            // bg-[#eff6ff] survives only as a class name, which app.css paints
            // #f4f6f5 in light mode; no colour value may be a pale blue.
            $withoutClassNames = preg_replace('/(bg|ring|border|text)-\\\\?\[\\\\?#eff6ff\\\\?\]/i', '', $source);

            foreach (['#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#f0f6ff', '#dbe4ff'] as $hex) {
                $this->assertStringNotContainsStringIgnoringCase($hex.';', $withoutClassNames, "$hex is still used in $file.");
                $this->assertStringNotContainsStringIgnoringCase("'$hex'", $withoutClassNames, "$hex is still used in $file.");
            }
        }
    }

    /** Collapsed rail: the Shop services list never pops out on hover. */
    public function test_the_collapsed_shop_hover_popup_is_gone(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));

        $this->assertStringNotContainsString('.nav-group:hover .nav-submenu', $layout);
        $this->assertStringNotContainsString('__sidebarFlyoutInit', $layout);
        $this->assertMatchesRegularExpression('/html\.dashboard-sidebar-collapsed \[data-flux-sidebar\] \.nav-submenu \{\s*display: none !important;\s*\}/', $layout);
        $this->assertStringContainsString('x-effect="$store.dashboardSidebar?.isCollapsed; expanded = false; locked = false"', $layout);
    }
}
