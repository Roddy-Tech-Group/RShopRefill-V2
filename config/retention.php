<?php

/*
|--------------------------------------------------------------------------
| Data retention
|--------------------------------------------------------------------------
|
| The windows the privacy policy (section 5) and the compliance page promise.
| Anything enforced by a scheduled command reads its number from here so the
| published figure and the running figure can never drift apart.
|
*/

return [

    /*
    | Account closure grace period. A customer who closes their account is
    | signed out at once, and their personal details are anonymised only after
    | this many days. Signing in again before the deadline cancels the closure.
    */
    'account_closure_grace_days' => (int) env('RETENTION_ACCOUNT_CLOSURE_GRACE_DAYS', 30),

    /*
    | Wallet balances below this USD-equivalent do not block closure: they are
    | too small to spend and are forfeited when the account closes. Wallet
    | funds are closed-loop (spend-only), so nothing is ever paid back out.
    */
    'account_closure_dust_threshold_usd' => (float) env('RETENTION_ACCOUNT_CLOSURE_DUST_THRESHOLD_USD', 1.0),

    /*
    | How long the identity of a closed account that moved money is kept in
    | closed_account_identities, counted from closure. MLPPA 2022 sets a
    | five-year floor for customer identification records.
    */
    'closed_account_identity_years' => (int) env('RETENTION_CLOSED_ACCOUNT_IDENTITY_YEARS', 5),

    /*
    | Financial records (orders, payments, wallet ledger, identity checks) of a
    | CLOSED account are removed this many years after closure. The privacy
    | policy publishes five to seven; seven is the ceiling it promises.
    */
    'closed_account_financial_years' => (int) env('RETENTION_CLOSED_ACCOUNT_FINANCIAL_YEARS', 7),

    /*
    | Email delivery logs (recipient, subject, status) and contact-form
    | messages: operational data with personal details, kept two years.
    */
    'communication_logs_days' => (int) env('RETENTION_COMMUNICATION_LOGS_DAYS', 730),
    'contact_messages_days' => (int) env('RETENTION_CONTACT_MESSAGES_DAYS', 730),

    /*
    |--------------------------------------------------------------------------
    | Records of processing (NDPA 2023 / GAID 2025)
    |--------------------------------------------------------------------------
    |
    | The register of what personal data we process, why, and for how long.
    | `retention:ropa` renders this into docs/compliance/records-of-processing.md
    | with the live retention figures above, so the register can never quote a
    | number the code does not run. A `:key` inside a retention string is
    | replaced with that key's value from this file.
    |
    */
    'controller' => [
        'name' => 'Roddy Technologies LTD (trading as RshopRefill)',
        // support@ is the one mailbox that receives mail; info@ has no inbox.
        'dpo_email' => 'support@rshoprefill.com',
        'website' => 'https://rshoprefill.com',
    ],

    'processors' => [
        'Flutterwave, Paystack, Korapay' => 'Card and bank payment processing',
        'NOWPayments' => 'Crypto payment processing',
        'Identity verification partner' => 'Identity document and selfie checks; documents are held by the partner, we hold the result',
        'Resend' => 'Transactional and marketing email delivery',
        'Google' => 'Sign-in with Google (identity federation)',
        'Sentry' => 'Error monitoring (error context may include IP address and user id)',
        'Hosting provider' => 'Application and database hosting, encrypted backups',
    ],

    'processing_activities' => [
        [
            'activity' => 'Account registration and sign-in',
            'purpose' => 'Create and secure the customer account; prevent unauthorised access',
            'data' => 'Name, email, phone, country, gender, password hash, Google identifier, avatar, trusted devices, IP address, session data',
            'subjects' => 'Customers',
            'lawful_basis' => 'Contract (Terms of Service); legitimate interest (security)',
            'recipients' => 'Google (sign-in), Resend (verification and security emails), hosting provider',
            'retention' => 'Life of the account, then anonymised :account_closure_grace_days days after closure',
        ],
        [
            'activity' => 'Orders and fulfilment',
            'purpose' => 'Sell and deliver gift cards, airtime, bill payments and eSIMs',
            'data' => 'Order contents, amounts, currency, delivery email, fulfilment codes, timestamps',
            'subjects' => 'Customers, gift recipients',
            'lawful_basis' => 'Contract; legal obligation (financial record-keeping)',
            'recipients' => 'Product suppliers (to fulfil the order), hosting provider',
            'retention' => 'Up to :closed_account_financial_years years after the account is closed (MLPPA 2022, tax law)',
        ],
        [
            'activity' => 'Payments and wallet ledger',
            'purpose' => 'Take payment, hold closed-loop wallet balances, keep an auditable ledger',
            'data' => 'Payment references, amounts, gateway responses, wallet balances and transactions, crypto payment addresses',
            'subjects' => 'Customers',
            'lawful_basis' => 'Contract; legal obligation (MLPPA 2022, CBN guidelines)',
            'recipients' => 'Payment processors',
            'retention' => 'Up to :closed_account_financial_years years after the account is closed',
        ],
        [
            'activity' => 'Identity verification (KYC)',
            'purpose' => 'Verify identity above value thresholds; anti-money-laundering compliance',
            'data' => 'Verification reference, provider, result and decision date. On a pass, the legal name, date of birth, document type, issuing country and expiry date read from the document (encrypted at rest). The document number, the document images and selfies are held by the verification partner, not by us',
            'subjects' => 'Customers',
            'lawful_basis' => 'Legal obligation (MLPPA 2022)',
            'recipients' => 'Identity verification partner',
            'retention' => 'Attempt records up to :closed_account_financial_years years after closure. At closure the legal identity leaves them: kept in the closed-account register for :closed_account_identity_years years when the account moved money, removed otherwise',
        ],
        [
            'activity' => 'Fraud and risk assessment',
            'purpose' => 'Detect and prevent fraud, chargebacks and account takeover',
            'data' => 'Risk scores and signals, device fingerprints, IP address, velocity counters, review outcomes',
            'subjects' => 'Customers',
            'lawful_basis' => 'Legitimate interest (fraud prevention); legal obligation (AML)',
            'recipients' => 'None outside the company',
            'retention' => 'Risk assessments 24 months (config/risk.php); labels and review outcomes kept as the audit trail',
        ],
        [
            'activity' => 'Customer communications',
            'purpose' => 'Transactional email (receipts, security codes, account notices) and, with consent, marketing',
            'data' => 'Email address, name, message subject and delivery status, communication preferences',
            'subjects' => 'Customers, newsletter subscribers',
            'lawful_basis' => 'Contract (transactional); consent (marketing, withdrawable at any time)',
            'recipients' => 'Resend',
            'retention' => 'Preferences until withdrawn or the account closes; delivery logs :communication_logs_days days',
        ],
        [
            'activity' => 'Customer support',
            'purpose' => 'Answer questions and resolve complaints',
            'data' => 'Name, email, message content, IP address',
            'subjects' => 'Customers, visitors',
            'lawful_basis' => 'Legitimate interest; contract',
            'recipients' => 'Support tooling, hosting provider',
            'retention' => 'Contact messages :contact_messages_days days',
        ],
        [
            'activity' => 'Audit logging',
            'purpose' => 'Record security-relevant and administrative actions for accountability',
            'data' => 'Actor id, action, before and after state of sensitive fields, IP address, timestamp',
            'subjects' => 'Customers, staff',
            'lawful_basis' => 'Legitimate interest (accountability); legal obligation',
            'recipients' => 'None outside the company',
            'retention' => 'Audit logs 730 days (config/audit.php)',
        ],
        [
            'activity' => 'Rights requests',
            'purpose' => 'Fulfil access, correction and deletion requests',
            'data' => 'Request timestamps, exported data, identity of the requester',
            'subjects' => 'Customers',
            'lawful_basis' => 'Legal obligation (NDPA 2023)',
            'recipients' => 'None outside the company',
            'retention' => 'Recorded in the audit log (730 days); the closed-account identity register :closed_account_identity_years years',
        ],
    ],

];
