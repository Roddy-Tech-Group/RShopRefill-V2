# RshopRefills V2

**Buy gift cards, eSIMs, mobile top-ups and bill payments. One ecosystem, all your digital solutions.**

RshopRefills is a digital commerce and wallet platform. V2 is a ground-up rebuild on a modern Laravel 12 stack with a domain-driven architecture, a multi-currency wallet, a full rewards economy, multi-gateway payments (card, mobile money, crypto, wallet), automated supplier fulfillment, and an installable PWA experience.

The first product of **Roddy Technologies** - built in Cameroon, serving the world.

---

## Table of contents

- [What's new in V2](#whats-new-in-v2)
- [What it sells](#what-it-sells)
- [Key features](#key-features)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Getting started](#getting-started)
- [Running the app](#running-the-app)
- [Testing](#testing)
- [Deployment](#deployment)
- [Project structure](#project-structure)
- [Credits](#credits)

---

## What's new in V2

V2 is not a patch on V1 - it is a rebuild around reliability, money-safety and scale.

- **Domain-driven architecture.** Business logic is grouped by domain (Wallet, Payment, Ledger, Fulfillment, Risk, Rewards, and more) instead of fat controllers.
- **Money-safe wallet + ledger.** Balances are locked then debited through an auditable ledger, with scheduled reconciliation jobs that detect and repair drift.
- **Multi-gateway checkout.** Card, mobile money, crypto and wallet in one flow, with region-aware payment-method selection.
- **RCOIN rewards economy.** Earn, redeem, refer, withdraw and convert - a complete loyalty layer, not a points counter.
- **Automated supplier fulfillment.** Orders fulfill asynchronously through provider integrations with webhooks, polling and retries.
- **Security hardening.** Global transaction-PIN enforcement, KYC tiers, Cloudflare Turnstile on every sensitive gate, fraud detection and a full audit trail.
- **Installable PWA.** Manifest, service worker and an iOS-aware install experience so the storefront behaves like a native app.
- **Search-ready SEO.** Centralized meta, Open Graph + Twitter cards, Organization JSON-LD and a dynamic sitemap covering every public page.

## What it sells

| Product             | Description                                                                 |
| ------------------- | --------------------------------------------------------------------------- |
| **Gift cards**      | Hundreds of global brands, one card per brand, region-aware pricing.        |
| **eSIMs**           | Travel data plans with QR + manual activation, top-ups and low-data alerts. |
| **Mobile top-ups**  | Airtime and data bundles for operators worldwide.                           |
| **Bill payments**   | Prepaid utilities and services.                                             |
| **Flights & stays** | Travel booking surfaces.                                                    |

## Key features

**Wallet & money**

- Multi-currency wallets (USD, NGN, GBP, GHS, XAF and RCOIN) with live FX conversion.
- Lock/debit ledger flow, manual admin credit/debit with mandatory reason + audit, and automated balance reconciliation jobs.
- Transaction PIN required on every wallet payment, with attempt lockout protection.

**Payments**

- Flutterwave (cards + mobile money), NowPayments (crypto) and Wallet, plus RCOIN, coupons and saved cards - with webhook verification and pending-funding recovery.

**Rewards (RCOIN)**

- Cashback on purchases, referral credits, redemption, withdrawal and conversion, with a per-user earnings multiplier admin control.

**Catalog & fulfillment**

- Synced catalog from suppliers (Zendit gift cards / top-ups / eSIMs, Airalo eSIMs), one-card-per-brand normalization, and asynchronous fulfillment with webhooks, polling and retries on dedicated queues.

**Accounts & trust**

- Email + KYC verification tiers with a Facebook-style verified badge, social login (Socialite), and suspension / ban / funds-hold controls.

**Admin operations**

- Customer management (edit, ban, suspend, hold funds, set KYC, reset transaction PIN, send password reset, log in as customer), catalog / commerce / fintech / notification dashboards, plus a CMS for blog, press, FAQs and reviews.

**Notifications**

- Multi-channel delivery (in-app database + email via Resend) with preferences, async dispatch, delivery monitoring and auto-retry.

**Experience**

- Country / language / currency switching, dark mode, installable PWA, and shared badges and components. Corner radii follow the existing component and approved design for each context.

## Tech stack

Version snapshot verified against the dependency locks and installed packages on **2026-09-17**. Use `composer.lock` and `package-lock.json` as the source for resolved versions after dependency updates.

| Layer | Technology |
| --- | --- |
| Backend | Laravel **12.69.1**; Composer requires PHP **^8.2** and resolves against PHP **8.2.0** |
| Views and interaction | Blade, Livewire **4.4.3**, Volt **1.10.5**, Flux UI **2.14.1**, bundled Alpine.js **3.17.1** |
| Styling and assets | Tailwind CSS **4.0.8**, Vite **6.4.3** |
| Animation | GSAP **3.15.0**, Lottie Web **5.13.0**, existing inline SVG illustrations |
| Background jobs and authentication | Laravel Horizon **5**, Laravel Socialite **5** |
| Development and verification | PHPUnit **11.5.55**, Laravel Pint **1.29.1**, Playwright **1.63.0**, Laravel Pail |

Additional libraries include ApexCharts, Flatpickr, jsVectorMap, Axios, Sharp, Dompdf, and Sentry. Dependency manifests list the full package set. Global typography uses DM Sans; Tailwind tokens and theme overrides live in `resources/css/app.css`.

The inspected local CLI uses **PHP 8.3.30** and **Node 22.19.0**. GitHub Actions uses **PHP 8.4** and **Node 22** for the test workflow. These runtime versions are separate from the PHP dependency requirement.

### Infrastructure configuration

| Service | Source default | Inspected local configuration |
| --- | --- | --- |
| Database | SQLite | MySQL |
| Cache | Database | Database |
| Sessions | Database | Database |
| Queue | Redis | Sync |
| Mail | Log | Resend |
| Filesystem | Local | Not inspected |

Local configuration was checked with `php artisan about --only=drivers --json` on **2026-09-17**. It reports selected drivers, not service availability or production configuration. The local broadcasting setting is `reverb`; that setting alone does not establish that a Reverb server is installed or running. Horizon is configured for Redis queues in `config/horizon.php`.

Implemented provider integrations include Zendit and Airalo for catalog and delivery; Flutterwave, Paystack, Korapay and NOWPayments for payments; Google OAuth; Smile ID; Resend; Web Push; and Cloudflare Turnstile. Availability depends on configuration and feature flags. SMS and WhatsApp channels are currently stubs.

Before making changes, read [the project rules](roddy-tech-website-rules.md) and [the agent guide](CLAUDE.md).

## Architecture

Business logic is organized into domains under `app/Domain`. The current directories are:

```
Admin  Audit  Auth  Cart  Catalog  Checkout  Communication
Fulfillment  Kyc  Ledger  Order  Payment  PaymentInsights
Product  Reconciliation  Rewards  Risk  Security  Shared
Transaction  Wallet
```

Domains contain services, provider contracts and implementations, jobs, events, listeners, enums and exceptions as needed. HTTP controllers and Livewire/Volt components delegate business operations to the domain layer. Blade renders the storefront; Alpine.js and the existing JavaScript modules handle browser interactions and animation.

## Getting started

**Requirements:** PHP satisfying **^8.2**, Composer, Node/npm, and a configured database. **Node 22** is the version used locally and in the test workflow. Source database configuration includes SQLite, MySQL, MariaDB, PostgreSQL and SQL Server; the inspected local application selects MySQL. Redis is needed when using Redis queues or Horizon; a synchronous local queue does not require a queue worker.

```bash
# 1. Clone + install
git clone https://github.com/Roddy-Tech-Group/rshoprefills-v2.git
cd rshoprefills-v2
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database
php artisan migrate --seed

# 4. Storage
php artisan storage:link
```

Before running migrations, configure `.env` for your database and choose the queue, cache and session drivers. Configure Redis if using Redis queues or Horizon. Set up the integrations you intend to use: Resend mail; Flutterwave, Paystack, Korapay or NOWPayments; Zendit and Airalo; Smile ID; Cloudflare Turnstile; and Google OAuth. Keep secrets out of committed files.

## Running the app

```bash
# All-in-one dev runtime (server, named queue listener, Vite)
composer run dev

# Or run the pieces individually
php artisan serve
php artisan queue:listen --queue=critical-alerts,emails,notifications,airalo,rewards,default --tries=1
npm run dev
```

`composer run dev` starts the three processes shown above. Pail is available as a development dependency but is not started by this script. With `QUEUE_CONNECTION=sync`, dispatched jobs execute immediately in the dispatching process. For asynchronous processing, configure the queue connection and workers for the queues your features dispatch to; the development listener covers only the names listed above. Horizon has a broader supervisor configuration for Redis deployments.

Build assets for production with `npm run build`.

## Testing

```bash
php artisan test --compact            # full suite
php artisan test --filter=SomeTest    # a single test
vendor/bin/pint                       # fix code style
```

`phpunit.xml` defines Unit and Feature suites using SQLite in memory, array cache/session/mail, and synchronous queues. Playwright is installed for browser checks; it is not run by the current GitHub Actions test workflow.

The workflows in `.github/workflows` run on pushes and pull requests to `main` and `develop`: `tests.yml` installs dependencies, builds assets and runs PHPUnit on Ubuntu with PHP 8.4 and Node 22; `lint.yml` runs Laravel Pint with PHP 8.4.

## Deployment

- Run `npm run build`, then cache config / routes / views:
    ```bash
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    ```
- For Redis queue deployments, run **Horizon** with the supervisors in `config/horizon.php` (requires the `pcntl` extension, available on Linux, not Windows local dev). These are deployment instructions; the production environment has not been verified by the local stack inspection.
- Serve over **HTTPS** so the PWA is installable and the service worker is active.
- Submit `/sitemap.xml` to Google and Bing Search Console.

## Project structure

```
app/
  Domain/             # Business logic by domain (services, providers, jobs, events)
  Http/Controllers/   # Thin HTTP layer (web + API + admin)
  Models/             # Eloquent models
  Jobs/               # Queued jobs (fulfillment, refunds, reconciliation)
resources/
  views/              # Blade + Livewire/Volt views (storefront, dashboard, admin)
  js/ | css/          # Frontend (Alpine, GSAP, Lottie, Tailwind)
routes/
  web.php | admin.php # Storefront/dashboard + admin routes
tests/                # PHPUnit feature + unit tests
```

## Credits

Built by **Roddy Technologies**.

- **Divine Ofeh** - Chief Executive Officer (CEO)
- **Madueke Johnpaul** - Chief Technology Officer (CTO)

Building an Africa-to-international digital ecosystem, from Cameroon to the world. RshopRefills is the team's first product, with continuous updates shipping to solve real problems for the community.
