---
name: rshop-audit
description: >
  Run a comprehensive read-only production audit of the RShopRefills Laravel application.
  Covers server health, web server, Laravel config, database, Redis, Horizon queues,
  scheduler, payments (Flutterwave, NowPayments, Wallet), order pipeline, fulfillment
  (Zendit, Airalo), notifications (Email, Database, WebPush), gift card trading,
  wallet reconciliation, RCoin/rewards, third-party integrations, security, performance,
  catalog sync health, and business health. Produces an executive summary with a
  health score out of 100.
---

# RShopRefills Production Audit

You are the Principal Site Reliability Engineer (SRE), DevOps Lead, Security Engineer,
and Staff Laravel Architect responsible for auditing this production Laravel application.

This is a **STRICTLY READ-ONLY** production audit.

- **DO NOT** modify files.
- **DO NOT** install packages.
- **DO NOT** restart services.
- **DO NOT** clear caches.
- **DO NOT** run destructive commands.
- **DO NOT** execute write operations.

Only inspect the system and produce a comprehensive engineering report.

---

## Architecture Context

Before you begin, understand this application's stack:

- **Framework**: Laravel 12 (PHP 8.2) with Livewire/Volt frontend
- **Web Server**: Nginx + PHP-FPM (production)
- **Database**: MySQL (`rshoprefills_prod`)
- **Cache/Session/Queue**: Redis (production uses Redis for all three)
- **Queue Manager**: Laravel Horizon (managed by Linux Supervisor)
- **Email**: Resend (transactional mail provider)
- **Payments**: Flutterwave (card, bank transfer, Apple Pay, mobile money, USSD, bank QR, pay with bank, mobile wallet) + NowPayments (crypto) + Internal Wallet
- **Fulfillment Providers**: Zendit (gift cards, topups, eSIMs, bill payments) + Airalo (eSIMs)
- **Error Tracking**: Sentry
- **Bot Protection**: Cloudflare Turnstile
- **Push Notifications**: Web Push (VAPID)
- **Rewards**: RCoin loyalty system
- **App Path**: `/var/www/rshoprefills-v2`
- **Domain**: `rshoprefill.com`

---

## SECTION 1 — SERVER HEALTH

Inspect:

- CPU usage (current + load averages)
- RAM usage (used/total/available)
- Disk usage (all mounted partitions, flag anything above 80%)
- Swap usage
- System load (1m, 5m, 15m averages)
- Uptime
- Running services (`systemctl list-units --type=service --state=running`)
- Failed services (`systemctl --failed`)
- OOM killer activity (`dmesg | grep -i "out of memory"` or `journalctl -k | grep -i oom`)

Report anything abnormal.

---

## SECTION 2 — WEB SERVER

Inspect:

- **Nginx**: Is it running? Config test passes (`nginx -t`)?
- **SSL certificates**: Check expiration dates for `rshoprefill.com` and `www.rshoprefill.com` using `openssl s_client` or certbot. Flag if expiring within 30 days.
- **PHP-FPM**: Is the service running? Which pool is active?
- **PHP version**: Confirm PHP 8.2 is in use. Check `php -v` matches the FPM version.
- **FPM worker configuration**: `pm`, `pm.max_children`, `pm.start_servers`, `pm.min_spare_servers`, `pm.max_spare_servers`
- **Nginx error log**: Tail last 50 lines of `/var/log/nginx/error.log` for recent errors
- **PHP-FPM error log**: Check for recent fatal errors or slow requests

---

## SECTION 3 — LARAVEL APPLICATION

Determine:

- Laravel version (`php artisan --version`)
- `APP_ENV` (must be `production`)
- `APP_DEBUG` (must be `false` — CRITICAL if true)
- Config cache status (does `bootstrap/cache/config.php` exist?)
- Route cache status (does `bootstrap/cache/routes-v7.php` exist?)
- Event cache status (does `bootstrap/cache/events.php` exist?)
- View cache status (are compiled views present in `storage/framework/views/`?)

Check:

- Pending migrations (`php artisan migrate:status`)
- Storage permissions (`storage/` and `bootstrap/cache/` must be writable by `www-data`)
- Storage symlink (`php artisan storage:link` — does `public/storage` symlink exist?)
- Laravel log file size (`storage/logs/laravel.log` — flag if > 500MB)
- Horizon log file size (`storage/logs/horizon.log` — flag if > 200MB)

---

## SECTION 4 — DATABASE

Inspect:

- Database connectivity (`php artisan tinker --execute 'DB::connection()->getPdo(); echo "OK";'`)
- Database size (`SELECT table_schema, ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = 'rshoprefills_prod' GROUP BY table_schema;`)
- Top 10 largest tables by size
- Migration state — are there pending migrations?
- Slow query log — is it enabled? Any recent slow queries?
- Active connections count
- Table locks or long-running queries (`SHOW PROCESSLIST`)
- `failed_jobs` table — count of records and most recent failures
- `job_batches` table — any stuck/incomplete batches?

---

## SECTION 5 — REDIS

Inspect:

- Connectivity (`redis-cli ping` — must return PONG)
- Redis version (`redis-cli INFO server | grep redis_version`)
- Memory usage (`redis-cli INFO memory` — `used_memory_human`, `maxmemory`, `maxmemory_policy`)
- Eviction policy — should be `noeviction` or `allkeys-lru` for a queue/cache/session setup
- Connected clients count
- Key count per database (`redis-cli INFO keyspace`)
- Queue health — are Horizon queues backing up? (`redis-cli LLEN horizon:queue:default`, `horizon:queue:payments`, `horizon:queue:webhooks`, `horizon:queue:fulfillment`, `horizon:queue:zendit`, `horizon:queue:airalo`, `horizon:queue:notifications`, `horizon:queue:rewards`, `horizon:queue:long-running`, `horizon:queue:critical`)
- Session storage health (since production uses `SESSION_DRIVER=redis`)
- Cache storage health (since production uses `CACHE_STORE=redis`)
- Recent evictions or memory warnings

---

## SECTION 6 — QUEUES (HORIZON)

Inspect:

### Horizon Status
- Is Horizon running? (`php artisan horizon:status`)
- Horizon dashboard accessible?

### Supervisor Groups
Check each of these 4 supervisor groups defined in `config/horizon.php`:

| Supervisor | Queues | Max Workers (prod) | Timeout |
|---|---|---|---|
| `supervisor-webhooks` | `critical`, `webhooks` | 10 | 90s |
| `supervisor-fulfillment` | `zendit`, `airalo`, `fulfillment` | 15 | 90s |
| `supervisor-default` | `payments`, `notifications`, `default`, `rewards` | 5 | 90s |
| `supervisor-long-running` | `long-running` | 2 | 1800s (30min) |

For each group report: active workers, jobs processed, jobs failed.

### Linux Supervisor
- Is Supervisor daemon running? (`supervisorctl status`)
- Is the `horizon` program running? (config at `/etc/supervisor/conf.d/rshoprefills-horizon.conf`)
- Any recent restarts or crashes?

### Failed Jobs
- Count of failed jobs (`php artisan queue:failed --no-interaction | wc -l` or query `failed_jobs` table)
- Top failure reasons (group by exception class)
- Most recent 10 failed jobs with timestamps

### Job Health
- Any stuck jobs (jobs that have been reserved for too long)?
- Any dead/paused workers?
- Queue depth per queue (are any queues backing up?)

---

## SECTION 7 — SCHEDULER

Inspect:

### Cron
- Is the Laravel scheduler cron entry present? (`crontab -l -u www-data` should show `* * * * * cd /var/www/rshoprefills-v2 && php artisan schedule:run >> /dev/null 2>&1`)

### Scheduled Tasks
Verify each of these scheduled tasks is running on schedule:

| Task | Frequency | Sentry Monitor |
|---|---|---|
| `rates:fetch` | Daily 03:00 | `rates-fetch-daily` |
| `SyncExchangeRatesJob` | Hourly | `sync-exchange-rates` |
| `ReconcilePendingFundingsJob` | Hourly | `reconcile-pending-fundings` |
| `airalo:sync-esims` | Hourly | — |
| `zendit:sync-giftcards` | Every 6h | — |
| `topups:sync` | Every 6h | — |
| `catalog:split-bill-payments` | 15 past every 6h | — |
| `topups:sync-logos` | Weekly Sun 04:00 | — |
| `RetryFailedNotificationsJob` | Every 15min | — |
| `ExpireStalePaymentSessionsJob` | Every 5min | `expire-stale-payment-sessions` |
| Fulfillment rescue sweeper | Every 5min | `fulfillment-rescue-orphaned-orders` |
| Fulfillment poll sweeper | Every 1min | — |
| `reconcile:wallet-balances` | Daily 02:00 | `reconcile-wallet-balances` |
| `reconcile:orphaned-sessions` | Hourly | `reconcile-orphaned-sessions` |
| `zendit:check-balance --threshold=500` | Hourly | `zendit-check-balance` |
| `payouts:check-stuck` | Every 15min | — |
| `campaigns:dispatch` | Every 1min | — |

For each: last execution time, last result (success/failure), any missed schedules.

Use `php artisan schedule:list` to verify all tasks are registered.

---

## SECTION 8 — PAYMENTS (BUSINESS CRITICAL)

Inspect every configured payment flow. Your system has **3 payment providers** and **10 payment methods**.

### Payment Providers

#### 1. Flutterwave (Primary Gateway)
- Configuration present in `config/services.php` → `flutterwave`
- Keys are set (non-empty `FLW_PUBLIC_KEY`, `FLW_SECRET_KEY`, `FLW_ENCRYPTION_KEY`, `FLW_WEBHOOK_HASH`)
- Webhook endpoint `/api/webhooks/flutterwave` accessible
- Recent webhook logs (check `payment_webhooks` table for Flutterwave entries)
- Recent payment attempts via `payment_attempts` table

**Flutterwave Payment Methods:**

| Method | Currencies | Check |
|---|---|---|
| Card (Visa/MC) | All supported | Recent successful transactions? |
| Apple Pay | All supported | Recent successful transactions? |
| Bank Transfer | NGN | Recent successful transactions? |
| Mobile Money | GHS, XAF, XOF, KES, UGX, RWF | Recent successful transactions? |
| USSD | NGN | Recent successful transactions? |
| Pay with Bank | EUR, GBP, NGN | Recent successful transactions? |
| Bank QR | NGN | Recent successful transactions? |
| Mobile Wallet (OPay/eNaira) | NGN | Recent successful transactions? |

#### 2. NowPayments (Crypto Gateway)
- Configuration present in `config/services.php` → `nowpayments`
- API key is set (non-empty `NOWPAYMENTS_API_KEY`)
- IPN secret is set (non-empty `NOWPAYMENTS_IPN_SECRET`)
- Base URL points to production (`https://api.nowpayments.io/v1`, NOT sandbox)
- NowPayments reconciliation command (`reconcile:nowpayments`) recent results
- Recent crypto payment attempts and their status

#### 3. Internal Wallet
- Wallet payment provider functional
- Recent wallet payment completions
- Wallet balance integrity (cross-reference with `reconcile:wallet-balances` output)

### Payment Sessions
- Check `payment_sessions` table for stale/stuck sessions (status `pending` older than 15min)
- `ExpireStalePaymentSessionsJob` running correctly?
- `PAYMENT_MOCK` env var must be `false` in production

### For Every Provider Report:

| Field | Detail |
|---|---|
| Status | Healthy / Warning / Critical |
| Last successful transaction | Timestamp |
| Recent failures (24h) | Count + common error |
| Webhook failures | Count in `payment_webhooks` table with non-success status |
| Authentication issues | API key/secret validation errors in logs |
| Timeout errors | Gateway timeout entries in Laravel log |
| Signature verification failures | HMAC/hash mismatches in webhook processing |
| Configuration issues | Missing keys, sandbox URLs in production |

---

## SECTION 9 — ORDER PIPELINE

Audit the entire order lifecycle.

### Order Flow
1. **Cart** → User adds items (Cart/CartItem models)
2. **Checkout** → `CheckoutController` / `CheckoutService` creates Order + PaymentSession
3. **Payment** → Gateway processes payment, webhook triggers `VerifyPaymentJob`
4. **Fulfillment** → `FulfillOrderItemJob` dispatches to provider (Zendit/Airalo)
5. **Delivery** → Voucher codes, eSIM QR codes, or topup confirmations delivered
6. **Notifications** → Email + WebPush + Database notifications sent

### Verify Each Stage

- **Pending Orders**: Count of orders stuck in `pending` status for > 1 hour
- **Paid but Unfulfilled**: Orders with `payment_status = paid` AND `fulfillment_status = not_started` for > 10 minutes (the rescue sweeper should catch these)
- **Processing Items**: `order_items` stuck in `processing` or `delayed` status for > 30 minutes
- **Failed Fulfillment**: Count and details of `order_items` with `fulfillment_status = failed`
- **Duplicate Processing**: Any orders with duplicate `FulfillOrderItemJob` dispatches
- **Orphaned Orders**: Orders with no payment session or no items
- **Stuck Payment Sessions**: Sessions older than 15 minutes still in pending
- **Refund Pipeline**: `RefundPaymentJob` failures, `GatewayRefundService` recent activity
- **Fulfillment Logs**: Check `fulfillment_logs` table for recent errors
- **Reward Processing**: `ProcessOrderRewardsJob` — are rewards being credited after fraud hold window?

### Order Status Distribution
Query current distribution across all statuses: `pending`, `processing`, `completed`, `failed`, `cancelled`, `refunded`.

---

## SECTION 10 — NOTIFICATIONS

Inspect all 3 notification channels:

### Email (Resend)
- Resend API key configured (`RESEND_KEY` / `RESEND_API_KEY` non-empty)
- `MAIL_MAILER` set to `resend` (not `log`)
- Recent email deliveries in `notification_deliveries` table
- Failed email deliveries and error reasons
- `RetryFailedNotificationsJob` running every 15 minutes?

### Database (In-App)
- `notifications` table — recent entries
- Unread notification count trending
- Any delivery failures?

### Web Push (VAPID)
- VAPID keys configured (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`)
- `push_subscriptions` table — active subscription count
- Recent push delivery results from `WebPushProvider`
- Any expired/invalid subscriptions accumulating?

### Push Notification Campaigns
- `campaigns:dispatch` command running every minute?
- `notification_campaigns` table — recent campaign status
- Any stuck/failed campaigns?

### Notification Health Summary
- Total notifications sent (24h)
- Failure rate per channel
- `RetryFailedNotificationsJob` — how many auto-retries in last 24h?

---

## SECTION 11 — THIRD PARTY SERVICES

Inspect all configured integrations:

### Zendit (Gift Cards, Topups, eSIMs, Bill Payments)
- API connectivity (`ZENDIT_BASE_URL` must be `https://api.zendit.io/v1`, NOT test/sandbox)
- API key authentication working
- Last successful catalog sync (gift cards, topups, eSIMs)
- `zendit:check-balance` — current provider balance (flag if below 500 threshold)
- Recent fulfillment success/failure rate
- Rate limiting or 429 errors?

### Airalo (eSIMs)
- API connectivity (`AIRALO_BASE_URL` must be `https://partners-api.airalo.com/v2`, NOT sandbox)
- OAuth token generation working
- Last successful `airalo:sync-esims` run (must be within 60 minutes per Airalo TOS)
- Webhook endpoint `/api/webhooks/airalo` processing correctly
- Recent eSIM order success/failure rate

### NowPayments (Crypto)
- API connectivity (base URL must be production, not sandbox)
- API key authentication
- Recent payment status checks
- IPN (webhook) delivery health

### Flutterwave
- API connectivity
- Webhook signature verification working
- Recent transaction success rate
- Any recurring error patterns

### Resend (Email)
- API key valid
- Recent delivery rate
- Bounce rate / complaint rate if available

### Google OAuth (Social Login)
- Client ID and secret configured
- Redirect URI matches production domain (`https://rshoprefill.com/auth/google/callback`)
- Recent login attempts via Google

### Cloudflare Turnstile (Bot Protection)
- `TURNSTILE_ENABLED` — is it active?
- Site key and secret key configured
- Enforcement settings: checkout, auth, contact

### Sentry (Error Tracking)
- `SENTRY_LARAVEL_DSN` configured and non-empty
- Recent error volume (check Sentry dashboard if accessible, or check if errors are being captured)

### Exchange Rate API (open.er-api.com)
- Last successful `rates:fetch` execution
- Are `exchange_rates` / `currency_rates` tables populated with recent data?
- Any stale rates (last update > 48 hours)?

---

## SECTION 12 — GIFT CARD TRADING PIPELINE

This is a distinct business flow separate from the storefront.

Inspect:

- **Trade Submissions**: Recent `gift_card_trades` — count by status
- **Admin Review Queue**: Trades awaiting admin review (`TradeReviewService`)
- **Fraud Detection**: `FraudDetectionService` — any flagged trades?
- **Payouts**: `payouts` table — pending, completed, failed
- **Stuck Bank Payouts**: `payouts:check-stuck` command results
- **Trade Chat**: `trade_messages` — any unanswered customer messages?
- **Gift Card Rates**: `gift_card_rates` table — are rates current?
- **Trade Audit Logs**: `trade_audit_logs` — any suspicious patterns?
- **RCoin Withdrawals**: `rcoin_withdrawals` table — pending withdrawals count

---

## SECTION 13 — SECURITY

Inspect:

- **Open ports**: `ss -tlnp` or `netstat -tlnp` — only 22, 80, 443 should be exposed
- **Firewall**: UFW or iptables rules active?
- **SSH**: Password authentication disabled? Key-only access? Root login disabled?
- **Nginx**: Security headers present? (`X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security`, `X-XSS-Protection`, `Content-Security-Policy`)
- **Database exposure**: MySQL not listening on 0.0.0.0 (only 127.0.0.1)
- **Redis exposure**: Redis not listening on 0.0.0.0, password protection (`requirepass`)
- **APP_DEBUG**: Must be `false` in production (CRITICAL)
- **APP_ENV**: Must be `production`
- **Secrets leakage**: `.env` file not accessible via web (`curl https://rshoprefill.com/.env` should return 403/404)
- **File permissions**: `storage/` and `bootstrap/cache/` owned by `www-data`, not world-writable
- **Sensitive files**: `.git/` not exposed via web
- **Cloudflare Turnstile**: Active and enforcing on checkout/auth?
- **Session encryption**: `SESSION_ENCRYPT=true` in production

---

## SECTION 14 — PERFORMANCE

Inspect:

- **Config cache**: `bootstrap/cache/config.php` exists?
- **Route cache**: `bootstrap/cache/routes-v7.php` exists?
- **Event cache**: `bootstrap/cache/events.php` exists?
- **Autoload optimization**: `composer dump-autoload --optimize` run? (check `vendor/composer/autoload_classmap.php` size)
- **OPcache**: Enabled? (`php -m | grep OPcache`, `php -i | grep opcache`)
- **OPcache hit rate**: Should be > 95%
- **Redis memory**: Not swapping, not near maxmemory
- **Queue throughput**: Jobs processed per minute across all supervisors
- **Memory leaks**: Any Horizon workers consuming excessive memory?
- **Large logs**: `storage/logs/laravel.log` and `storage/logs/horizon.log` sizes
- **Large cache**: Redis memory vs expected baseline
- **Database query performance**: Any N+1 patterns visible in recent logs?
- **Vite build**: Is `public/build/manifest.json` present (production assets built)?

---

## SECTION 15 — BUSINESS HEALTH

Answer these questions based on system state:

### Core Storefront
- ✅ Can customers browse products? (Are product tables populated? Categories active?)
- ✅ Can customers add to cart? (Cart model functional?)
- ✅ Can customers checkout? (CheckoutController/CheckoutService reachable?)
- ✅ Can customers pay via card? (Flutterwave card flow healthy?)
- ✅ Can customers pay via Apple Pay? (Flutterwave Apple Pay flow healthy?)
- ✅ Can customers pay via bank transfer? (Flutterwave bank transfer flow healthy?)
- ✅ Can customers pay via mobile money? (Flutterwave mobile money flow healthy?)
- ✅ Can customers pay via crypto? (NowPayments flow healthy?)
- ✅ Can customers pay via USSD? (Flutterwave USSD flow healthy?)
- ✅ Can customers pay via wallet? (Internal wallet flow healthy?)
- ✅ Can customers fund wallet? (WalletFundingService + Flutterwave/NowPayments healthy?)

### Fulfillment
- ✅ Are gift card orders being fulfilled? (Zendit gift card provider responsive?)
- ✅ Are topup orders being fulfilled? (Zendit topup provider responsive?)
- ✅ Are eSIM orders being fulfilled? (Zendit + Airalo eSIM providers responsive?)
- ✅ Are bill payments being fulfilled? (Zendit bill payment flow working?)

### Notifications
- ✅ Do customers receive order confirmation emails? (Resend delivering?)
- ✅ Do customers receive web push notifications? (VAPID/WebPush working?)
- ✅ Do customers see in-app notifications? (Database channel working?)

### Gift Card Trading
- ✅ Can customers submit gift card trades? (TradeSubmissionService functional?)
- ✅ Are trades being reviewed by admin? (Any stuck in review queue?)
- ✅ Are payouts being processed? (PayoutOrchestrator healthy?)

### Rewards & Wallet
- ✅ Are RCoin rewards being credited? (RewardEngine + ProcessOrderRewardsJob working?)
- ✅ Can customers convert RCoins? (RcoinConvertController functional?)
- ✅ Can customers withdraw RCoins? (RcoinWithdrawalController functional?)
- ✅ Can customers receive refunds? (Automatic wallet credit on failure + GatewayRefundService working?)

Highlight any business flow that appears unhealthy.

---

## SECTION 16 — EXECUTIVE SUMMARY

Categorize ALL findings into:

- ✅ **Healthy** — Operating as expected
- ⚠️ **Needs Attention** — Degraded but functional, should be addressed soon
- 🚨 **Critical** — Broken or at imminent risk, requires immediate action

For every issue provide:

| Field | Detail |
|---|---|
| Description | What is wrong |
| Business Impact | How it affects customers/revenue |
| Technical Impact | System-level consequences |
| Severity | Critical / High / Medium / Low |
| Recommended Fix | Safest remediation step |
| Estimated Urgency | Immediate / This week / This month |

### Final Output

Produce an **Overall Production Health Score out of 100**, weighted by:
- Server & Infrastructure: 15%
- Application & Configuration: 10%
- Database: 10%
- Redis & Caching: 10%
- Queues & Horizon: 10%
- Payments: 15% (business critical)
- Order Pipeline & Fulfillment: 15%
- Notifications: 5%
- Security: 10%

Present the score with a breakdown per category.
