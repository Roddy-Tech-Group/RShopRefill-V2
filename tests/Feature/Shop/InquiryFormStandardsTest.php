<?php

namespace Tests\Feature\Shop;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared inquiry form (partners tab, affiliates page, request a product)
 * behaves the way a form is expected to: fields announce what they take and
 * how much, a failed submit marks the fields it failed and says so once at
 * the top, the button cannot be pressed twice, and a link typed without its
 * scheme is still a link.
 */
class InquiryFormStandardsTest extends TestCase
{
    use RefreshDatabase;

    private function form(): string
    {
        return $this->withoutVite()->get(route('shop.affiliates'))->assertOk()->getContent();
    }

    /**
     * @return array<string, string>
     */
    private function validInquiry(): array
    {
        return [
            'name' => 'Ada Creator',
            'email' => 'ada@example.test',
            'company' => '@adacreates',
            'company_url' => 'instagram.com/adacreates',
            'role' => '25,000 followers',
            'category' => 'Instagram',
            'message' => 'I review travel gear and would love to feature your eSIMs.',
        ];
    }

    public function test_fields_say_what_they_take_and_how_much(): void
    {
        $html = $this->form();

        $this->assertMatchesRegularExpression('/<input id="inquiry-name"[^>]*autocomplete="name"[^>]*maxlength="255"/', $html);
        $this->assertMatchesRegularExpression('/<input id="inquiry-email"[^>]*type="email"[^>]*autocomplete="email"[^>]*inputmode="email"/', $html);
        $this->assertMatchesRegularExpression('/<input id="inquiry-company_url"[^>]*type="text"[^>]*inputmode="url"[^>]*autocomplete="url"[^>]*maxlength="500"/', $html);
        $this->assertMatchesRegularExpression('/<textarea id="inquiry-message"[^>]*minlength="10"[^>]*maxlength="5000"/', $html);

        // Placeholders take a set colour rather than the browser's half-tone.
        $this->assertStringContainsString('placeholder:text-zinc-500', $html);

        // Every optional field says so, the select included; the asterisks are
        // decoration for sighted readers, the required attribute does the talking.
        $this->assertMatchesRegularExpression('/for="inquiry-category"[^>]*>[^<]*<span[^>]*>\(optional\)/', $html);
        $this->assertStringContainsString('<span class="text-zinc-500" aria-hidden="true">*</span>', $html);
        $this->assertStringContainsString('<p id="inquiry-message-hint" class="mt-1 text-xs text-zinc-500">Between 10 and 5,000 characters.</p>', $html);

        // One required-fields note, under the fields; the heading is the invitation alone.
        $this->assertStringContainsString('<p class="text-xs text-zinc-500">* Required fields</p>', $html);
        $this->assertStringNotContainsString('Fields marked with an asterisk are required.', $html);
        $this->assertLessThan(strpos($html, '* Required fields'), strpos($html, 'id="inquiry-message"'));
    }

    public function test_the_pitch_column_and_card_follow_the_partner_layout(): void
    {
        $html = $this->form();

        // The tinted ground under both columns, the icon tile, the large heading,
        // three default steps, the benefits panel, and the white 12px card.
        // On the storefront's grey page the panel is white and the form card grey,
        // so both read as layers in light mode.
        $this->assertStringContainsString('<div class="rounded-[12px] bg-white p-4 shadow-md shadow-zinc-900/[0.06] sm:p-6 lg:p-8 dark:shadow-none">', $html);
        $this->assertStringContainsString('rounded-[12px] bg-zinc-900 text-white dark:bg-white dark:text-zinc-900', $html);
        $this->assertStringContainsString('text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">How it works</h2>', $html);
        foreach (['Send us your details', 'We review your inquiry', 'You hear back from us by email'] as $step) {
            $this->assertStringContainsString($step, $html);
        }
        $this->assertStringContainsString('Free to join', $html);
        $this->assertStringContainsString('<div class="rounded-[12px] bg-zinc-100 p-6 sm:p-8">', $html);
        $this->assertStringContainsString('>Join us today</h2>', $html);

        // Fields are 12px with a 1px edge, not pills.
        $this->assertMatchesRegularExpression('/<input id="inquiry-name"[^>]*class="w-full rounded-\[12px\] border border-zinc-300/', $html);

        // Half-width cells are columns and their labels take the spare height, so
        // a wrapping label cannot push its field below the one beside it.
        $at = strpos($html, 'action="'.route('affiliates.send').'"');
        $form = substr($html, $at, strpos($html, '</form>', $at) - $at);
        $this->assertSame(5, substr_count($form, '<div class="flex flex-col">'));
        $this->assertMatchesRegularExpression('/<div x-data="\{ open: false, category: [^}]*\}"[^>]*class="flex flex-col">/', $form);

        // The platform list anchors under its button, not at the top of the
        // column, which is where a flex column would otherwise place it.
        $this->assertMatchesRegularExpression('/<div class="relative">\s*<button\s+id="inquiry-category"/s', $form);
        $this->assertMatchesRegularExpression('/id="inquiry-category-list"[^>]*class="absolute left-0 right-0 top-full z-20/s', $form);
        $this->assertSame(7, substr_count($form, 'class="mb-2 block flex-1 text-sm font-semibold text-zinc-900"'));

        // The consent line replaces the reassurance and links to the policy.
        $this->assertStringNotContainsString('We never share your details.', $html);
        $this->assertStringContainsString('By submitting this form you agree to', $html);
        $this->assertStringContainsString('href="'.route('shop.privacy').'"', $html);
    }

    public function test_the_button_cannot_be_pressed_twice_and_nothing_wears_a_decorative_icon(): void
    {
        $html = $this->form();
        // The tag spans several lines, so find the form by its action and walk
        // back to the tag that opens it.
        $at = strpos($html, 'action="'.route('affiliates.send').'"');
        $this->assertNotFalse($at);
        $form = substr($html, strrpos(substr($html, 0, $at), '<form'));
        $form = substr($form, 0, strpos($form, '</form>'));

        // Sending state, and grey until the browser says every required field is good.
        $this->assertStringContainsString('x-data="{ sending: false, valid: false }"', $form);
        $this->assertStringContainsString('@submit="sending = true"', $form);
        $this->assertStringContainsString('x-init="valid = $el.checkValidity()"', $form);
        $this->assertStringContainsString(':disabled="sending || ! valid"', $form);
        $this->assertStringNotContainsString(' disabled ', $form);
        $this->assertStringContainsString('<span x-show="! sending">Submit application</span>', $form);

        // The paper plane on the button and the arrow on the contact link.
        $this->assertStringNotContainsString('M6 12L3.269 3.126', $form);
        $this->assertStringNotContainsString('M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3', $html);
    }

    public function test_a_failed_submit_marks_each_field_and_says_so_once_at_the_top(): void
    {
        $this->from(route('shop.affiliates'))
            ->post(route('affiliates.send'), [
                'name' => '',
                'email' => 'nope',
                'company' => '',
                'company_url' => 'not a link',
                'message' => 'short',
            ])
            ->assertRedirect(route('shop.affiliates'))
            ->assertSessionHasErrors(['name', 'email', 'company', 'company_url', 'message']);

        $html = $this->withoutVite()->get(route('shop.affiliates'))->assertOk()->getContent();

        $this->assertStringContainsString('Please check the highlighted fields below.', $html);
        $this->assertMatchesRegularExpression('/<input id="inquiry-company_url"[^>]*border-red-400[^>]*aria-invalid="true" aria-describedby="inquiry-company_url-error"/', $html);
        $this->assertStringContainsString('<p id="inquiry-company_url-error" role="alert"', $html);
        $this->assertMatchesRegularExpression('/<textarea id="inquiry-message"[^>]*aria-invalid="true"[^>]*aria-describedby="inquiry-message-hint inquiry-message-error"/', $html);
        $this->assertStringContainsString('<p id="inquiry-message-error" role="alert"', $html);
    }

    public function test_the_platform_picker_is_the_apps_own_dropdown_not_the_browsers_select(): void
    {
        $html = $this->form();

        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('<input type="hidden" name="category" :value="category">', $html);
        $this->assertMatchesRegularExpression('/<button\s+id="inquiry-category"\s+type="button"[^>]*aria-haspopup="listbox"/s', $html);
        $this->assertStringContainsString('id="inquiry-category-list"', $html);
        $this->assertStringContainsString('role="listbox"', $html);

        // Every platform is an option, and the empty state reads "Select one".
        $list = substr($html, strpos($html, 'id="inquiry-category-list"'));
        $list = substr($list, 0, strpos($list, '</div>'));
        foreach (['Instagram', 'TikTok', 'YouTube', 'Blog or website', 'Other'] as $platform) {
            $this->assertStringContainsString('<span>'.$platform.'</span>', $list);
        }
        $this->assertSame(9, substr_count($list, 'role="option"'));
        $this->assertStringContainsString("x-text=\"category || 'Select one'\"", $html);
    }

    public function test_a_chosen_platform_survives_a_failed_submit(): void
    {
        $this->from(route('shop.affiliates'))
            ->post(route('affiliates.send'), ['category' => 'TikTok', 'message' => 'short'] + $this->validInquiry())
            ->assertSessionHasErrors('message');

        $html = $this->withoutVite()->get(route('shop.affiliates'))->assertOk()->getContent();

        $this->assertStringContainsString("x-data=\"{ open: false, category: 'TikTok' }\"", $html);
    }

    public function test_the_dashboard_partners_tab_renders_the_same_form(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.rewards'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Join us today</h2>', $html);
        $this->assertStringContainsString('<span x-show="! sending">Submit application</span>', $html);
        $this->assertStringContainsString('action="'.route('affiliates.send').'"', $html);

        // Inside the dashboard's white card the panel is grey and the form card white.
        $this->assertStringContainsString('<div class="rounded-[12px] bg-[#f4f6f5] p-4 sm:p-6 lg:p-8 dark:bg-white/5">', $html);
        $this->assertStringContainsString('<div class="rounded-[12px] bg-white p-6 shadow-md shadow-zinc-900/[0.06] sm:p-8 dark:shadow-none">', $html);

        // The platform picker is the dropdown here too, with every option.
        $this->assertStringNotContainsString('<select', substr($html, strpos($html, 'action="'.route('affiliates.send').'"')));
        $this->assertStringContainsString('id="inquiry-category-list"', $html);
        $this->assertSame(9, substr_count($html, 'role="option"'));
    }

    public function test_a_link_typed_without_its_scheme_is_accepted_and_kept_as_https(): void
    {
        $this->post(route('affiliates.send'), $this->validInquiry())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('contact_sent', true);

        $contact = ContactMessage::latest('id')->first();

        $this->assertNotNull($contact);
        $this->assertStringContainsString('Profile or website: https://instagram.com/adacreates', $contact->message);
    }

    public function test_a_link_that_is_not_one_is_refused(): void
    {
        $this->post(route('affiliates.send'), ['company_url' => 'not a link'] + $this->validInquiry())
            ->assertSessionHasErrors('company_url');

        $this->assertSame(0, ContactMessage::count());
    }
}
