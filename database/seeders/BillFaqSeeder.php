<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The bill payment store's own FAQ, written by the business.
 *
 * It lives in the faqs table rather than in the page, for the same reason the
 * gift card, eSIM and homepage FAQs do: the admin FAQ editor stays the one
 * place these are written, /faq and the store page can never drift apart, and
 * changing a line does not need a deploy. The page pulls the "Bill Payments"
 * topic through <x-home.faq>.
 *
 * The brand detail page already answers three questions from the per-locale products lang files
 * (speed, what is needed, what happens on failure). Those are per-biller; these
 * are the store-wide questions a shopper asks before picking a biller.
 *
 * Idempotent: each row is matched on topic + question, so re-running this
 * updates the answer instead of duplicating the entry.
 */
class BillFaqSeeder extends Seeder
{
    private const TOPIC = 'Bill Payments';

    public function run(): void
    {
        $faqs = [
            ['What is a bill payment?', 'A bill payment settles a prepaid utility account, such as electricity or water, directly from RShopRefill. You select the biller, enter the account or meter number and pay, and the value is applied to that account.'],
            ['Which bills can I pay?', 'The store lists every biller currently available for the country you have selected. Use the country filter to see what can be paid where, since available billers differ by region and change as new ones are added.'],
            ['How quickly is a bill payment applied?', 'Most payments reach the biller within a few minutes of your payment being confirmed. The biller then applies it to the account, which can take longer during busy periods.'],
            ['What details do I need to pay a bill?', 'You need the account, meter or customer number exactly as it appears on your bill or meter, and the country the account belongs to. Some billers also ask for the account holder name.'],
            ['What happens if I enter the wrong account or meter number?', 'The payment is applied to whichever account matches the number you entered and cannot be reversed. RShopRefill cannot recover a payment made to a valid account that was not yours, so check the number carefully before paying.'],
            ['Will I receive a token or receipt?', 'Yes. Prepaid meters return a token or reference once the biller processes the payment. It appears in your order along with the payment receipt, and a copy is sent to your delivery email.'],
            ['Can I change the account or amount after paying?', 'No. Once a payment has been sent to a biller it cannot be recalled, redirected or resized. Review the account number and the amount before you confirm payment.'],
            ['What payment methods can I use?', 'RShopRefill supports available payment methods such as mobile money, bank transfers, cards, cryptocurrency and eligible wallet balances. Available options may vary by country and currency.'],
            ['What should I do if my payment has not been applied?', 'Check the status of your order from the Orders page and confirm the account number you entered. If payment succeeded but the biller did not apply it, contact RShopRefill Support with your order reference. Failed eligible orders may be automatically refunded.'],
            ['Can a bill payment be refunded?', 'Bill payments are final once the biller has accepted them. Refunds may be considered when an order fails or nothing is delivered at all. A payment applied to the account you entered cannot be returned.'],
            ['Can I pay a bill for someone else?', 'Yes. You can pay any supported bill using that account or meter number, including accounts in other countries. You only need the account details themselves.'],
            ['Can I earn Rcoin on bill payments?', 'Eligible bill payments may earn Rcoin after the order is successfully completed. The amount and eligibility will be displayed during your purchase or in the Rcoin Rewards section.'],
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
