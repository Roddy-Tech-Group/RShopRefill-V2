<?php

/**
 * Order history and order detail.
 *
 * Order references, transaction IDs and redemption codes are identifiers.
 * They are shown verbatim in both languages.
 */
return [
    'orders' => 'Orders',
    'my_orders' => 'My orders',
    'order' => 'Order',
    'order_reference' => 'Order reference',
    'order_date' => 'Order date',
    'status' => 'Status',
    'status_pending' => 'Pending',
    'status_processing' => 'Processing',
    'status_completed' => 'Completed',
    'status_failed' => 'Failed',
    'status_refunded' => 'Refunded',
    'no_orders' => 'You have no orders yet',
    'view_order' => 'View order',
    'download_codes' => 'Download codes',
    'refund_requested' => 'Refund requested',
    'refund_issued' => 'Refund issued',

    // Order page (shop/order.blade.php): the rest of the status badge. The
    // enum's own label() is English-only, so the page reads status_{value}.
    'status_cancelled' => 'Cancelled',
    'status_partially_completed' => 'Partially Completed',
    'status_requires_attention' => 'Requires Attention',
    'status_awaiting_payment' => 'Awaiting payment',
    'status_expired' => 'Expired',

    // Order page hero: one title and one line per order state. The unpaid
    // and expired pairs override the state when no money ever moved.
    'hero_completed_title' => 'Your order is complete',
    'hero_completed_line' => 'Your redemption codes are ready below and have been emailed to you.',
    'hero_partial_title' => 'Your order is partially complete',
    'hero_partial_line' => 'Some items are ready below. The rest are still being processed and will be emailed shortly.',
    'hero_processing_title' => 'Payment received',
    'hero_processing_line' => 'We are fulfilling your order. Codes land in your email the moment each item is ready.',
    'hero_failed_title' => 'This order could not be completed',
    'hero_failed_line' => 'Your payment did not go through and no charge was taken. You can try checking out again.',
    'hero_cancelled_title' => 'This order was cancelled',
    'hero_cancelled_line' => 'No payment was taken for this order.',
    'hero_review_title' => 'We are reviewing your order',
    'hero_review_line' => 'This order needs a quick review. Our team will update you by email shortly.',
    'hero_placed_title' => 'Thank you, your order is placed',
    'hero_placed_line' => 'We are confirming your payment. Your redemption codes are emailed as soon as it clears.',
    'hero_unpaid_title' => 'Complete your payment',
    'hero_unpaid_line' => 'Your items are reserved but not yet paid for. Finish your payment to receive them.',
    'hero_expired_title' => 'This checkout expired',
    'hero_expired_line' => 'You were not charged. Your items are still available if you would like to try again.',
    'complete_payment' => 'Complete payment',
    // :email is wrapped in a bold span by the page.
    'confirmation_sent_to' => 'A confirmation has been sent to :email',

    // Customer-facing payment method names. Generic on purpose: no gateway
    // or provider is ever named to a customer.
    'method_wallet' => 'Wallet',
    'method_crypto' => 'Crypto',
    'method_card' => 'Card',

    // Completed order view and the delivered gift card. Resellers screenshot
    // that card for their own customers, so the credential labels sit in a
    // narrow column: keep them to about four letters.
    'success_heading' => 'Order completed',
    'success_thanks' => 'Thank you for your purchase',
    'download_csv' => 'Download as CSV',
    'item_fallback_name' => 'Item',
    'card_fallback_name' => 'Your card',
    'card_for_brand' => 'For :brand',
    'esim_qr_alt' => 'eSIM activation QR code',
    'esim_scan_hint' => 'Scan this QR from another device to install your eSIM.',
    'topup_credited_to' => 'Credited to',
    'topup_will_be_credited_to' => 'Will be credited to',
    'credential_code' => 'Code',
    'credential_pin' => 'Pin',
    'copy_credential' => 'Copy :label',
    'code_pending' => 'Your code appears here once payment clears.',
    'view_all_orders' => 'View all my orders',

    // Redeem instructions and terms panels under the completed order. Brand
    // names and redemption URLs inside the steps stay as they are.
    'redeem_instructions' => 'Redeem instructions',
    'terms_and_conditions' => 'Terms and conditions',
    'redeem_gift_cards_heading' => 'Gift cards',
    'redeem_gift_cards_step_1' => "Visit the brand's redemption page (e.g. apple.com/redeem for Apple, amazon.com/gc for Amazon).",
    'redeem_gift_cards_step_2' => 'Sign in or create an account in the matching region.',
    'redeem_gift_cards_step_3' => 'Enter the PIN/code above exactly as shown - codes are case-sensitive and single-use.',
    'redeem_gift_cards_step_4' => 'The balance is added to your account immediately.',
    'redeem_esims_heading' => 'eSIMs',
    'redeem_esims_step_1' => "Open Settings on iOS (or your device's mobile network settings on Android).",
    'redeem_esims_step_2' => 'Go to Cellular or Mobile Data, then "Add eSIM" / "Add cellular plan".',
    'redeem_esims_step_3' => 'Scan the QR code shown on the card above.',
    'redeem_esims_step_4' => 'Follow the prompts to label and activate. The plan starts on first network connection in the destination country.',
    'redeem_topups_heading' => 'Mobile top-ups',
    'redeem_topups_body' => 'The top-up has been credited directly to the phone number shown above - no further action needed. If the credit does not appear within 15 minutes, contact support with this order number.',
    'terms_codes_cash' => 'Codes are equivalent to cash. :site cannot reissue codes that have been viewed, shared or used.',
    'terms_esims' => 'eSIM data plans activate on first network connection. The validity period (where applicable) starts from that point.',
    'terms_topups' => 'Mobile top-ups are non-refundable once credited to the destination phone number.',
    // :link is the terms_full_legal_link text, rendered as a link by the page.
    'terms_full_legal' => 'For the full legal terms see our :link.',
    'terms_full_legal_link' => 'Terms and Conditions',

    // Payment panel shown on an order whose payment session is still open.
    // The messages below are defaults: a message the payment session returns
    // is shown instead when there is one. :status and :text are the HTTP
    // status code and reason, filled in by the browser.
    'pay_heading' => 'Complete Your Payment',
    'pay_choose_method' => 'Choose your preferred payment method below to complete order #:number.',
    'pay_card_heading' => 'Enter Card Details',
    'pay_cardholder_name' => 'Cardholder Name',
    'pay_cardholder_placeholder' => 'e.g. John Doe',
    'pay_card_number' => 'Card Number',
    'pay_expiry' => 'Expiry (MM/YY)',
    'pay_expiry_month_placeholder' => 'MM',
    'pay_expiry_year_placeholder' => 'YY',
    'pay_button' => 'Pay',
    'pay_pin_heading' => 'Card PIN Required',
    'pay_pin_help' => 'Enter your card 4-digit security PIN to authorize payment.',
    'pay_pin_confirm' => 'Confirm PIN',
    'pay_otp_heading' => 'OTP Verification',
    'pay_otp_verify' => 'Verify OTP',
    'pay_otp_prompt' => 'Enter verification code',
    'pay_otp_sent' => 'Verification code sent',
    'pay_3ds_heading' => 'Secure Verification',
    'pay_3ds_help' => 'Please complete the secure authentication inside the window below.',
    'pay_3ds_done' => 'I Have Completed Payment',
    'pay_momo_heading' => 'Mobile Money Details',
    'pay_momo_phone' => 'Phone Number',
    'pay_momo_phone_placeholder' => 'e.g. 237670000000',
    'pay_momo_network' => 'Network',
    'pay_momo_authorize' => 'Please authorize payment on your mobile money device.',
    'pay_momo_accept_prompt' => 'Please accept the billing prompt on your device.',
    'pay_bank_heading' => 'Virtual Bank Transfer',
    'pay_bank_help' => 'Please make a transfer to the temporary virtual account below:',
    'pay_bank_name' => 'Bank Name',
    'pay_bank_account_number' => 'Account Number',
    'pay_bank_account_name' => 'Account Name',
    'pay_bank_amount' => 'Amount',
    'pay_crypto_heading' => 'Crypto Payment Details',
    'pay_crypto_help' => 'Send the exact amount of cryptocurrency shown to the address below:',
    'pay_crypto_qr_alt' => 'Payment QR code',
    'pay_crypto_scan' => 'Scan to pay',
    'pay_crypto_currency' => 'Cryptocurrency',
    'pay_crypto_network' => 'Network: :network',
    'pay_crypto_amount' => 'Amount to Send',
    'pay_crypto_address' => 'Deposit Address',
    'pay_waiting_transfer' => 'Waiting for transfer...',
    'pay_waiting_transfer_help' => 'Status updates automatically. This temporary reference expires in 30 minutes.',
    'pay_authorize_heading' => 'Authorize on Phone',
    'pay_verifying' => 'Verifying payment authorization...',
    'pay_processing' => 'Processing transaction...',
    'pay_do_not_close' => 'Please do not close this window.',
    'pay_complete_heading' => 'Payment complete',
    'pay_complete_help' => 'Your order status is refreshing now.',
    'pay_failed_heading' => 'Payment Failed',
    'pay_try_another' => 'Try Another Method',
    'pay_error_server' => 'Server error (:status): :text',
    'pay_error_initiation' => 'Payment initiation failed.',
    'pay_error_network' => 'Network connection failed. Please check your internet.',
    'pay_error_incomplete' => 'Transaction could not be completed.',
    'pay_error_failed' => 'Transaction failed.',
    'pay_copied_alert' => 'Copied to clipboard!',

    // Order summary on a pending or unfinished order. :qty, :price, :current
    // and :total are numbers and amounts filled in by the page.
    'date_placed' => 'Date placed',
    'items' => 'Items',
    'line_qty' => 'Qty :qty',
    'line_each' => ':price each',
    'code_n_of_total' => 'Code :current of :total',
    'redemption_details' => 'Redemption details',
    'esim_qr_short_alt' => 'eSIM QR',
    'fulfillment_failed' => 'Fulfillment failed',
    'pending_topup' => 'Credited to your phone once payment clears',
    'pending_esim' => 'eSIM QR delivered once payment clears',
    'pending_code' => 'Code emailed once payment clears',
    'total_paid' => 'Total paid',
    'points_earned' => 'Points earned',

    // "What happens next" steps, worded by what the order contains. :email
    // is the delivery address, or next_your_email when there is none.
    'next_heading' => 'What happens next',
    'next_confirm_title' => 'Confirm payment',
    'next_confirm_body' => 'We verify your payment with the provider. This is usually instant.',
    'next_codes_title' => 'Codes are issued',
    'next_codes_body' => 'Each item is fulfilled and its redemption code attached to your order.',
    'next_esim_title' => 'eSIM is generated',
    'next_esim_body' => 'Your eSIM QR is generated and attached to this order.',
    'next_topup_title' => 'Top-up is processed',
    'next_topup_body' => 'Your top-up is sent to the recipient phone number.',
    'next_delivery_codes_title' => 'Delivery',
    'next_delivery_codes_body' => 'Codes arrive at :email and stay available on this page.',
    'next_delivery_esim_title' => 'eSIM ready',
    'next_delivery_esim_body' => 'Your QR + activation code land at :email and on this page.',
    'next_delivery_topup_title' => 'Confirmation',
    'next_delivery_topup_body' => 'A confirmation lands at :email the moment the credit posts.',
    'next_your_email' => 'your email',

    // Order page footer. :reference is the order number, shown verbatim.
    'go_to_orders' => 'Go to orders',
    'continue_shopping' => 'Continue shopping',
    'need_help' => 'Need help with this order? Contact support with reference :reference.',
];
