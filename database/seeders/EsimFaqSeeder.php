<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The eSIM store's own FAQ, written by the business.
 *
 * It lives in the faqs table rather than in the page, for the same reason the
 * gift card and homepage FAQs do: the admin FAQ editor stays the one place
 * these are written, /faq and the store pages can never drift apart, and
 * changing a line does not need a deploy. The pages pull the "eSIMs" topic
 * through <x-home.faq>.
 *
 * It replaced four questions that were hardcoded in an @foreach on the eSIM
 * detail page, which no one could edit without a deploy and which said nothing
 * about coverage, compatibility, roaming or refunds.
 *
 * Idempotent: each row is matched on topic + question, so re-running this
 * updates the answer instead of duplicating the entry.
 */
class EsimFaqSeeder extends Seeder
{
    private const TOPIC = 'eSIMs';

    public function run(): void
    {
        $faqs = [
            ['What is an eSIM?', 'An eSIM is a digital SIM that lets you activate a mobile data plan without inserting a physical SIM card. Once purchased, you can install it on a compatible device using the provided QR code or activation details.'],
            ['What is the difference between country, regional and global eSIMs?', 'A country eSIM covers one destination. A regional eSIM works across multiple countries within a region, such as Africa, Europe or Asia. A global eSIM provides coverage across many destinations under one plan. Always check the listed coverage before purchasing.'],
            ['How will I receive my eSIM?', 'Your eSIM installation details and instructions will appear in your RShopRefill account after successful payment and order processing. Supported eSIMs are usually delivered within minutes.'],
            ['Is my device compatible with eSIMs?', 'Compatibility depends on your device model, country of manufacture and mobile carrier. Your device must support eSIM technology and be carrier-unlocked. Check our compatible-device list and your phone settings before purchasing.'],
            ['When should I install my eSIM?', 'We recommend installing your eSIM before travelling while you still have a stable internet connection. However, some plans begin when installed, while others begin only after connecting to a supported network. Always review the activation policy shown on the eSIM page.'],
            ['How do I activate my eSIM?', 'Open your order and follow the installation instructions provided. You may need to scan a QR code or enter the activation details manually. When you arrive at your destination, select the eSIM for mobile data and enable data roaming for that line.'],
            ['Do I need to enable data roaming?', 'Yes. Data roaming usually needs to be enabled for your eSIM to connect to its supported partner networks. This does not affect roaming charges from your primary SIM as long as mobile data is assigned to the eSIM.'],
            ['Can I use calls and SMS with my eSIM?', 'Most eSIMs provide mobile data only. You can still make calls and send messages through internet-based applications such as WhatsApp, Telegram, FaceTime and Messenger. If a plan includes calls, SMS or a phone number, this will be clearly stated before purchase.'],
            ['Can I continue using my physical SIM?', 'Yes. Compatible dual-SIM devices can keep your physical SIM active while using the eSIM for mobile data. To avoid unexpected roaming charges, confirm that mobile data switching is disabled and that your eSIM is selected for data.'],
            ['Can I install the same eSIM on another device?', 'Usually not. Most eSIMs can only be installed once and cannot be transferred after installation. Do not delete the eSIM from your device until the plan has expired or you have finished using it.'],
            ['How long does an eSIM plan remain valid?', 'Validity varies by package and may begin on purchase, installation or first connection to a supported network. The duration and activation policy are displayed on each eSIM package before checkout.'],
            ['Can I top up my eSIM?', 'Some eSIM plans support top-ups, while others require you to purchase a new package. Available top-up options will be shown in your account or on the relevant eSIM page.'],
            ['What affects eSIM speed and coverage?', 'Speed and coverage depend on your destination, location, device, selected plan, local network availability and network conditions. Your eSIM may connect to different partner networks as you travel.'],
            ['What should I do if my eSIM is not working?', 'Confirm that the eSIM is installed and enabled, data roaming is turned on, the eSIM is selected for mobile data and you are within a supported destination. Restart your device and follow any APN or network-selection instructions provided. If the problem continues, contact RShopRefill Support without deleting the eSIM.'],
            ['Can I cancel or refund an eSIM purchase?', 'If you purchased the wrong eSIM, contact RShopRefill Support immediately. Refund or replacement eligibility depends on whether the eSIM has been installed, activated or used and on the provider\'s applicable terms. Used, expired or successfully activated plans may not be refundable.'],
            ['What payment methods can I use?', 'RShopRefill supports available payment methods such as Mobile Money, Orange Money, bank transfers, cards, cryptocurrency and eligible wallet balances. Available options may vary by country and currency.'],
            ['Can I earn Rcoin when purchasing an eSIM?', 'Eligible eSIM purchases may earn Rcoin after the order is successfully completed. The applicable reward will be displayed during your purchase or within the Rcoin Rewards section.'],
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
