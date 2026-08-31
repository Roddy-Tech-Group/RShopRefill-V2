# Data protection runbook

How we handle a privacy request or incident, end to end. The system does most of the work; this is the people process around it. Owner: the Data Protection Officer (DPO mailbox: `info@rshoprefill.com`, as published on the privacy page).

Deadlines below are the ones we hold ourselves to. NDPA 2023 sets response deadlines that sources read as 7 to 30 days; we work to 7.

## 1. Access request ("send me my data")

**Self-serve (preferred).** Point the customer to Account settings > Danger zone > Download your data. They receive a one-time code by email, enter it, and a PDF downloads. Each download is written to the audit log as `user.data_exported`.

**By email.** If the customer cannot sign in:

1. Reply the same day acknowledging the request and asking them to confirm from the email address on the account (this is the identity check; never send data to a different address).
2. Once confirmed, open the customer in the admin panel to verify the address matches.
3. Ask an engineer to run the export for that user and send the PDF to the account email only:
   `php artisan tinker --execute 'echo json_encode(app(App\Domain\Auth\Services\DataExportService::class)->build(App\Models\User::find(ID)));'` gives the raw data; the PDF template is `dashboard/data-export-pdf`.
4. Record the fulfilment in the audit log if it was done outside the app (an engineer can log `user.data_exported` with a metadata note).
5. Target: 7 days from the request.

## 2. Correction request

Customers can change name, phone, country, gender and email in settings. For anything they cannot change themselves, verify identity as in section 1, make the change from the admin customer page (audited automatically), and confirm by email.

## 3. Deletion request ("close my account")

**Self-serve.** Account settings > Close account. The customer is signed out, the account closes after a 30-day grace period unless they sign in again, and their personal details are anonymised by `retention:close-accounts`. Financial records stay for the period required by law (see the records of processing).

**By email.** If the customer cannot sign in:

1. Reply asking them to confirm from the account email address.
2. Open the customer in the admin panel and click **Close account**. The same rules apply as self-serve: a wallet balance above the dust threshold or an order still in flight blocks it, and the page tells you why. Wallet funds are closed-loop and are never refunded; the customer must spend them first.
3. The click is audited as `admin.account_closure_requested`. The customer receives the same closure email.
4. If they change their mind inside the grace period, they sign in (which cancels it) or you click **Cancel closure**.

What we cannot do: delete orders, payments, wallet history or identity-verification results on request. Explain that financial and anti-money-laundering law requires us to keep them, that they are no longer linked to the customer by name once the account is anonymised, and that they are removed automatically once the legal period ends.

## 4. Marketing objection / consent withdrawal

Customers can switch off marketing in settings or via the unsubscribe link in every marketing email. Transactional messages (receipts, security codes, account notices) cannot be switched off while the account is open.

## 5. Where the identity of a closed account lives

When an account that moved money is anonymised, its name, email, phone, country and verification reference are copied into `closed_account_identities` (encrypted) for 5 years. This is for regulator, bank or payment-partner enquiries only ("who was account #N"). Access it through an engineer; never use it to contact the person.

## 6. Data breach

1. Contain first (rotate credentials, revoke sessions with `php artisan tinker` or the admin panel, block the vector).
2. Open an incident in `docs/ONCALL.md` terms and write down: what data, whose, how many, when it started, how it was found.
3. Assess risk to the people affected. If it is likely to result in harm, notify the NDPC within 72 hours of becoming aware, and notify affected customers without undue delay in plain language: what happened, what data, what we did, what they should do.
4. Keep the timeline; it is part of the audit trail.

## 7. Automated housekeeping (what runs without anyone)

| Command | When | What |
|---|---|---|
| `retention:close-accounts` | daily 03:15 | anonymise accounts past the grace period |
| `retention:enforce` | daily 03:30 | purge closed-account identities (5y), closed-account financial records (7y), delivery logs and contact messages (2y) |
| `risk:prune` | daily 02:30 | risk assessments past 24 months |
| `audit:prune` | monthly | audit logs past 730 days |

Every run logs what was eligible and what was removed. `--dry-run` is available on the retention commands.

## 8. Keeping the register current

`docs/compliance/records-of-processing.md` is generated from `config/retention.php` by `php artisan retention:ropa --write`. When a new processor is added, a new kind of data is collected, or a retention window changes, update the config, re-run the command, and commit the result. Review the whole register at least once a year and before any NDPC audit (GAID 2025 requires a compliance audit within 15 months of commencement, then annually).
