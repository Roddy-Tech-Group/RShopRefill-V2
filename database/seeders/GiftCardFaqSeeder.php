<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The gift card store's own FAQ, written by the business.
 *
 * It lives in the faqs table rather than in the page, for the same reason the
 * homepage FAQ does: the admin FAQ editor stays the one place these are
 * written, /faq and the store page can never drift apart, and changing a line
 * does not need a deploy. The page pulls the "Gift Cards" topic through
 * <x-home.faq>.
 *
 * Idempotent: each row is matched on topic + question, so re-running this
 * updates the answer instead of duplicating the entry, and any edits made in
 * the admin editor to a question's WORDING are left alone (they become new
 * rows only if the question text itself changed).
 */
class GiftCardFaqSeeder extends Seeder
{
    private const TOPIC = 'Gift Cards';

    public function run(): void
    {
        $faqs = [
            ['What is a digital gift card?', 'A digital gift card is a prepaid code that can be used to purchase products, games, subscriptions or services from a specific brand. Your gift card code is delivered digitally after your order is completed.'],
            ['How will I receive my gift card?', 'Your gift card code and redemption instructions will appear in your RShopRefill account after successful payment and order processing. Supported products are usually delivered within minutes.'],
            ['Are gift cards restricted by country or region?', 'Yes. Many gift cards are region-locked and only work with accounts registered in the selected country. Always confirm the country, currency and account region before purchasing.'],
            ['Can I change the country or value after purchasing?', 'No. Gift card brands, countries and denominations cannot normally be changed after the code has been delivered. Please review your selection carefully before completing payment.'],
            ['How do I redeem my gift card?', 'Open your order, copy the delivered code and follow the provided redemption instructions. You must redeem it through the official website, application or store of the selected brand.'],
            ['What payment methods can I use?', 'RShopRefill supports available payment methods such as mobile money, bank transfers, cards, cryptocurrency and eligible wallet balances. Available options may vary by country and currency.'],
            ['What should I do if my gift card has not arrived?', 'Check the status of your order from the Orders page. If payment was successful but the code was not delivered, contact RShopRefill Support with your order reference. Failed eligible orders may be automatically refunded.'],
            ['Can delivered gift cards be refunded?', 'Gift cards are generally final once a valid code has been delivered. Refunds may be considered when an order fails or no usable code is delivered. Codes that have already been viewed, redeemed or used cannot be returned.'],
            ['What if my gift card code does not work?', 'Confirm that the card matches your account country, currency and platform. Enter the code exactly as delivered. If it still fails, contact Support with your order reference and a screenshot of the error. Never share the complete code publicly.'],
            ['Can I buy a gift card for someone else?', 'Yes. You can purchase a gift card and securely send the delivered code to someone you trust. Make sure their account supports the gift card\'s specified country and currency.'],
            ['Can I earn Rcoin when buying gift cards?', 'Eligible gift card purchases may earn Rcoin after the order is successfully completed. The amount and eligibility will be displayed during your purchase or in the Rcoin Rewards section.'],
            ['How can I avoid gift card scams?', 'Only share gift card codes with their intended recipient. RShopRefill will never ask you to send your complete code through social media, chat or an unsolicited phone call. Treat every delivered code like cash.'],
        ];

        foreach ($faqs as $i => [$question, $answer]) {
            Faq::updateOrCreate(
                ['topic' => self::TOPIC, 'question' => $question],
                [
                    'answer' => $answer,
                    'is_published' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }
}
