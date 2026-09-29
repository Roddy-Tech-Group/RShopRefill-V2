<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pill-shaped fields (nav search, locale searches, the product, eSIM,
 * reviews, availability and pay-currency pickers) were briefly filled with a
 * tint of the interactive blue. That read as light blue in both themes and was
 * reverted: the blue stays on the border only, and each field is back on the
 * neutral fill it had before.
 */
class FieldFillTest extends TestCase
{
    /** A plain or dark: fill in the edge blue. Hover variants (the solid Search button's) do not count. */
    private const EDGE_TINT_FILL = '/(?<=[\s"\'])(?:dark:)?bg-edge\/\d+/';

    public function test_no_view_fills_a_control_with_a_tint_of_the_edge_blue(): void
    {
        foreach (File::allFiles(resource_path('views')) as $view) {
            preg_match_all(self::EDGE_TINT_FILL, $view->getContents(), $matches);

            $this->assertSame([], $matches[0], "{$view->getRelativePathname()} fills a control with the edge blue.");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fields(): array
    {
        return [
            'desktop nav search' => ['components/nav/main-nav.blade.php', 'rounded-full border-2 bg-[#eff6ff] pl-4 pr-1'],
            'phone nav search' => ['components/nav/main-nav.blade.php', "'border-edge bg-[#ffffff] dark:border-edge dark:bg-[#0c1a36]' : 'border-[#e4e4e7] bg-[#f4f6f5] dark:border-white dark:bg-white/8'"],
            'language search' => ['components/nav/language-switcher.blade.php', 'border-2 border-edge bg-[#ffffff] pl-10'],
            'locale modal search' => ['components/nav/locale-modal.blade.php', 'border-2 border-edge bg-[#ffffff] pl-10'],
            'product amount picker' => ['shop/product.blade.php', 'border-2 bg-white pl-3.5 pr-2.5'],
            'country availability picker' => ['shop/_country-availability.blade.php', 'border-2 bg-white px-3.5'],
            'reviews picker' => ['components/shop/product-reviews.blade.php', 'border-2 bg-white px-3.5'],
            'eSIM region picker' => ['shop/esim.blade.php', 'border-2 bg-transparent px-3.5'],
            'pay currency picker' => ['shop/_pay-currency-picker.blade.php', 'pure-card flex w-full items-center gap-2 border-2 bg-[#eff6ff]'],
        ];
    }

    #[DataProvider('fields')]
    public function test_each_field_is_back_on_its_neutral_fill(string $view, string $fill): void
    {
        $this->assertStringContainsString($fill, File::get(resource_path('views/'.$view)));
    }
}
