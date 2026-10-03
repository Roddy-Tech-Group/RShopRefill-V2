# RShopRefill Mobile API (v2): how to use it

This is the guide that goes with [openapi.yaml](openapi.yaml). The YAML file says
what each endpoint takes and returns. This page says in what order to call them and
what each answer means for the screen you show. Read this first, then generate your
types from the YAML.

The contract is enforced: `tests/Feature/Api/V2/OpenApiContractTest.php` checks the
YAML against the running code on every test run. If an endpoint changes and the file
does not, the build fails.

## 1. The shape of every answer

Success:

```json
{ "data": { ... } }
```

A list adds a page block. Pass `next_cursor` back as `?cursor=` for the next page.
It is opaque; never build or edit it.

```json
{ "data": [ ... ], "page": { "per_page": 24, "next_cursor": "eyJ...", "has_more": true } }
```

Failure, always, whatever the status code:

```json
{ "error": { "type": "insufficient_balance", "message": "Your wallet does not cover this order." } }
```

Branch on `type`. It never changes meaning and is never renamed. `message` is for the
customer and may be reworded, translated or improved at any time. Some types carry
extra fields (`retry_after`, `fields`, `suspension`); the YAML lists them.

Money is always a decimal **string**, never a float:

```json
{ "amount": "1060.00", "currency": "NGN", "usd": "0.6605" }
```

Catalogue and cart prices are converted into the currency you send in
`X-Display-Currency` and `usd` is alongside. Orders and payments are historical: what
was charged, never re-converted. Wallet balances and product face values are
`{ "amount", "currency" }` in their own currency with no `usd`, because they are facts,
not prices.

## 2. Headers

| Header | Value | When |
|---|---|---|
| `Authorization` | `Bearer <access_token>` | every signed-in call |
| `X-Installation-ID` | a stable id generated once per install | every call; required on sign-in and push routes |
| `X-App-Build` | the Expo build number | every call |
| `X-Display-Currency` | `NGN`, `USD`, … from `/auth/me` | catalogue and cart calls |
| `Idempotency-Key` | a fresh UUID per tap | every money action (see section 5) |
| `Accept-Language` | `en`, `fr`, … | every call |

**The installation id** is the identity of the phone. Generate it once on first launch
(a UUID is fine), keep it in secure storage, and never change it. Device trust, the
24-hour new-device hold, refresh-token binding and per-device sign-out all hang off it.
If it changes, every sign-in looks like a new phone and asks for an email code.

**The build number** lets us retire a broken build. When a build is below the minimum,
every call answers `426 upgrade_required` with `minimum_build`. Show "please update"
and stop retrying.

## 3. Signing in and staying signed in

### Tokens

A sign-in gives two tokens:

- **access token**, lasts 60 minutes, sent on every call;
- **refresh token**, lasts 30 days, single use, bound to this installation.

When a call answers `401 unauthenticated`, exchange the refresh token at
`POST /auth/refresh`. You get a **new pair**; store both and discard the old refresh
token. If the refresh itself answers `401` (any of `refresh_token_invalid`,
`refresh_token_expired`, `refresh_token_revoked`, `refresh_token_reused`), forget
both tokens and show the sign-in screen. Do not retry.

If the reply to a refresh is lost in a tunnel, retrying with the same refresh token
within 60 seconds returns the same new pair. After that, the token is treated as
stolen and the whole chain for this installation is revoked. That is deliberate.

Changing the password (or an admin action) signs every phone out at once. The next
refresh fails and the app returns to sign-in.

### The sign-in answer has two shapes

`POST /auth/register`, `/auth/login` and `/auth/google` all answer with `data.status`:

- `authenticated`: `data.tokens` and `data.user`. Done.
- `verification_required`: we emailed a six-digit code because this phone has not
  signed in to this account before. There are **no tokens** in this answer. Show the
  code screen with `sent_to` (masked address), a countdown from `code_expires_in`
  seconds, and a resend button that unlocks after `resend_available_in` seconds. Then:
  - `POST /auth/challenge/verify` with `challenge_token` and `code`. Success answers
    `authenticated` with tokens.
  - `POST /auth/challenge/resend` with `challenge_token` to send it again.
  - `422 code_invalid` means wrong or expired code, let them try again.
    `401 code_burned` or `challenge_expired` means start the sign-in over.

The challenge is tied to the installation that started it. Finishing it on another
phone is refused.

### Google

Sign in with Google natively in the app and send us the **ID token** (not the access
token). `201` is a new account, `200` an existing one; both carry the sign-in shape
above. `401 google_token_rejected` means sign in again. `503 google_unavailable` means
Google, or our configuration of it, is down: show "try again", not "sign in again".

### Account states you will meet

| type | status | what to show |
|---|---|---|
| `email_verification_required` | 403 | the verify-your-email screen; a resend button unlocks after `resend_retry_after` seconds (`POST /auth/email/resend`) |
| `account_suspended` | 403 | the message, with `suspension.reason` and `suspension.until` |
| `account_protected` | 403 | the account is in recovery; the message says what to do |
| `account_banned`, `account_closed` | 403 | sign out |
| `device_restricted` | 403 | "this action is held for 24 hours on a new device" |
| `maintenance` | 503 | the notice; stop retrying money calls |
| `too_many_requests` | 429 | wait `retry_after` seconds |

## 4. Browsing and the basket

1. `GET /catalog/config` once at launch: the display currencies and Rcoin settings.
2. `GET /catalog/categories`, `GET /catalog/products?category=…`. Every product has
   `price_from`. eSIMs are ordinary products in the `esims` category.
3. `GET /catalog/products/{slug}` for the variants. A variant with `is_variable: true`
   has `price: null`: the customer types an amount between `min_amount` and
   `max_amount` in the product's own currency, and you call `POST /catalog/quote` to
   show the exact price before adding it.
4. `POST /cart/items` with `variant_id`, `quantity`, `amount` (variable only) and the
   `metadata` the product needs: `recipient_phone` for a top-up, `delivery_email` for a
   gift card, `account_id` for a bill. This call carries an `Idempotency-Key`, because
   adding the same variant twice raises its quantity.
5. `GET /cart` returns `is_checkout_ready` and `issues`. Do not offer checkout while
   `issues` is non-empty; each entry says what is wrong with that line.

## 5. Idempotency keys: one per tap

Every call marked as needing an `Idempotency-Key` creates or moves money:
`POST /checkout/orders`, `POST /payments/{session}/pay`, `POST /payments/{session}/top-up`,
`POST /wallets/fund`, and `POST /cart/items`.

Rules:

- Generate a fresh UUID when the customer taps. Reuse that same key for **retries of
  that tap** (timeout, dropped connection). The server returns the first answer with
  the header `Idempotent-Replay: true`.
- `409 request_in_progress` means the first attempt is still running. Wait a second
  and retry with the same key.
- After **any refusal** (a 4xx), the next tap is a new action: new key.
- Sending a different body under an old key is `422 idempotency_key_reused`. That is
  an app bug.
- Keys are remembered for 24 hours.

## 6. Paying: follow `next_action`

Placing an order (`POST /checkout/orders`) or funding a wallet (`POST /wallets/fund`)
returns a `payment` object. **Do not infer what to do from `status`.** Read
`payment.next_action.type`:

| type | what the app does |
|---|---|
| `choose_method` | show the payment methods for this currency; call `POST /payments/{id}/pay` with the one picked |
| `confirm_with_pin` | ask for the wallet PIN, exchange it at `POST /transaction-pin/verify` for `auth_token`, then `pay` with `method: wallet, details.auth_token` |
| `open_url` | open `url` in an in-app browser; when the customer comes back, call `verify`, then poll |
| `open_inline` | render the card gateway's secure form from `inline`; then `verify` |
| `send_crypto` | show `address`, `memo`, the exact coin `amount`, `network` and a QR from `qr_payload`; poll |
| `bank_transfer` | show the `bank` account details; poll |
| `enter_otp` | the card issuer sent a one-time code; collect it and `pay` again with `otp` |
| `enter_card_pin` | collect the card PIN and `pay` again with `pin` |
| `await_approval` | a mobile money prompt is on the customer's phone; poll |
| `none` | settled, failed, expired or cancelled; read `status` and `failure` |

Polling is `GET /payments/{id}`, **at most every 5 seconds**. It is cheap. `verify`
asks the gateway and is rate limited: call it once when the customer returns from a
gateway page, and at most every few seconds after that if the deep link was missed.
If the app is killed before the payment settles, the gateway's own notification
settles it anyway; when the app reopens, read the order.

A payment stops being payable when the order behind it is replaced or closed:
`409 order_not_payable`, `order_already_paid`, `payment_session_expired`. Start the
checkout again.

A wallet payment: one PIN token authorises one payment. A second tap while the first
is running is `409 request_in_progress`; wait and poll.

An underpaid crypto payment (the exchange took a fee) is never fulfilled as it stands.
`payment.underpayment` says what arrived and what is missing; if `can_top_up` is true,
`POST /payments/{id}/top-up` issues a quote for the difference.

### Refusals you will see at checkout

| type | status | what to show |
|---|---|---|
| `needs_identity_verification` | 422 | explain and open identity verification (`required_tier`, `in_review`) |
| `spending_limit_reached` | 422 | the message; offer verification |
| `held_for_review` | 422 | "we are looking at this", stop retrying |
| `payment_refused` | 403 | the message, nothing more |
| `payment_paused` | 429 | a countdown from `retry_after`; `locked: true` is a long lock, point at support |
| `payment_method_not_allowed_for_items` | 422 | offer another method |
| `gateway_unavailable`, `gateway_disabled` | 503 | offer another method |
| `insufficient_balance` | 422 | offer to top up the wallet |
| `transaction_pin_required` | 422 | send them to set a PIN |
| `wallet_on_hold` | 422 | the message; point at support |
| `coupon_invalid` | 422 | let them fix or remove the code (`fields.coupon_code`) |
| `rates_unavailable` | 503 | try again shortly or choose another currency |
| `payment_failed` | 400 | generic; offer another method |

## 7. Wallets and the PIN

`GET /wallets` lists every wallet with `balance`, `locked_balance` (held against
payments in progress) and `available_balance`. `kind` is `points` for Rcoin, `cash`
for the rest. Rcoin is earned and redeemed, never bought: `POST /wallets/fund` refuses it.

The transaction PIN is four digits and authorises spending. `GET /transaction-pin`
says whether one is set and whether it is locked. Setting a first PIN is open;
changing or resetting one sits behind the 24-hour new-device hold.

## 8. Orders

`GET /orders` is newest first, paged. `payment_method` is the customer's word (`card`,
`wallet`, `crypto`, `mobile_money`, …), never a gateway name. `GET /orders/{number}`
includes items and, for fulfilled items, `delivery`: voucher `pins` and an `esim`
block with the install details.

## 9. Push notifications

Call `POST /push/token` on every launch and whenever Expo rotates the token. It is
safe to call constantly. Signing out (`POST /auth/logout`) removes this phone's
registration; so does changing the password.

## 10. Generating types and trying it out

- **TypeScript types:** `npx openapi-typescript docs/api/v2/openapi.yaml -o src/api/v2.d.ts`
- **Postman / Insomnia:** import `openapi.yaml` directly.
- **A browsable page:** admins can open it in the admin panel under Management, API docs.

## 11. What is not here yet

- Changing the email address. On the web this re-proves the password and
  re-verifies the new address; a thinner version is not offered.
- Starting identity verification from the app. `GET /account/kyc` is read only.
- Push notification *sending*. Phones can register; nothing sends yet.
