<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\ComplianceCategory;
use App\Domain\Shared\Enums\PaymentGateway;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The payment eligibility gate.
 *
 * Answers one question at every point money can move: may THIS compliance
 * category be charged through THIS gateway right now? The rules live in
 * config/payment_eligibility.php and default to deny for every restricted
 * category, so a missing or misconfigured rule blocks money rather than
 * leaking it (fail closed).
 *
 * Enforcement is behind a master switch (features.payment_eligibility_enforce,
 * seeded off) so the gate ships in shadow mode: it records what it WOULD have
 * blocked without turning any customer away, and is switched to enforcing
 * deliberately once the matrix reflects real approvals. This mirrors the
 * progressive-backoff shadow/enforce pattern.
 *
 * This is a defensive control. It enforces which gateway may charge a category;
 * it does not, on its own, pick the gateway (that is FiatGatewayRouter) and it
 * is not a substitute for the fraud/risk engine.
 */
class PaymentEligibilityService
{
    /**
     * Is the gate actively turning customers away, or only observing? Defaults
     * to false: a control whose matrix has not yet been checked against real
     * approvals should measure first and enforce on a deliberate flip.
     */
    public function enforcementEnabled(): bool
    {
        if (! FeatureFlag::on('payment_eligibility_enforce', false)) {
            return false;
        }

        // Enforcement with ZERO approved cells refuses every restricted cart
        // on every gateway. The admin UI refuses both ways of getting here
        // (enforcing with no cells, and switching off the last cell while
        // enforcing), so this state only exists through a write path that
        // bypassed it. It used to drop back to shadow, which turned a lockdown
        // into allow-all the moment the last cell went off. A lockdown stays
        // a lockdown; someone is told loudly and fixes the matrix.
        if ($this->approvedCellCount() === 0) {
            $this->reportEmptyMatrixUnderEnforcement();
        }

        return true;
    }

    /**
     * Would switching this cell off leave enforcement running with no
     * approved cell at all? The admin UI refuses that flip.
     */
    public function isLastApprovedCell(string $cellFlagKey): bool
    {
        if (! FeatureFlag::on('payment_eligibility_enforce', false)) {
            return false;
        }

        return $this->approvedCellCount() === 1 && FeatureFlag::on($cellFlagKey, false);
    }

    private function reportEmptyMatrixUnderEnforcement(): void
    {
        Log::critical('payment_eligibility.enforcing_with_no_approved_cells', [
            'reason' => 'enforcement is on and no eligibility cell is approved; every restricted-category cart is being refused',
        ]);

        try {
            app(OperationalAlerter::class)->send(
                severity: AlertSeverity::Sev2,
                key: 'payment_eligibility:enforcing-empty-matrix',
                title: 'Eligibility enforcement is on with no approved cells',
                message: 'Every restricted-category cart is being refused on every gateway. Approve the right gateway x category cells, or switch enforcement off.',
                context: ['admin' => 'Settings > Payments > Eligibility'],
                runbook: 'docs/runbooks/payment-provider-failure.md',
            );
        } catch (\Throwable) {
            // Alerting is best effort; the refusal is what matters.
        }
    }

    /**
     * How many (gateway, category) eligibility cells are currently approved.
     * Drives the zero-cell enforcement interlock and the admin UI's pre-flight
     * check before the enforce flag may be turned on.
     */
    public function approvedCellCount(): int
    {
        $count = 0;

        foreach ((array) config('payment_eligibility.cells', []) as $category => $gateways) {
            $categoryEnum = ComplianceCategory::tryFrom((string) $category);

            if (! $categoryEnum) {
                continue;
            }

            foreach ((array) $gateways as $gateway) {
                if (FeatureFlag::on($this->cellFlag($categoryEnum, $gateway), false)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Is this single category allowed on this gateway? Normal digital goods are
     * never gated; every restricted category reads its own admin-toggleable
     * feature flag and defaults to deny (fail closed). This ignores the
     * enforcement flag (that decides whether we ACT on a denial, not what the
     * policy is). Currency is accepted for the future currency-scoped matrix and
     * ignored today.
     */
    public function allows(ComplianceCategory $category, string $gateway, ?string $currency = null): bool
    {
        if (! $category->isRestricted()) {
            return true;
        }

        return FeatureFlag::on($this->cellFlag($category, $gateway), false);
    }

    /**
     * The admin-toggleable flag name for a (gateway, category) cell. Passed to
     * FeatureFlag::on, which prefixes 'features.' and suffixes '_enabled', e.g.
     * eligibility_flutterwave_gift_card -> features.eligibility_flutterwave_gift_card_enabled.
     */
    public function cellFlag(ComplianceCategory $category, string $gateway): string
    {
        return 'eligibility_'.$this->normaliseGateway($gateway).'_'.$category->value;
    }

    /**
     * Are ALL of the cart's categories allowed on this gateway? One payment
     * cannot span gateways, so a mixed cart is payable on a gateway only if that
     * single gateway approves every category in it.
     *
     * @param  iterable<ComplianceCategory>  $categories
     */
    public function allowsAll(iterable $categories, string $gateway, ?string $currency = null): bool
    {
        foreach ($categories as $category) {
            if (! $this->allows($category, $gateway, $currency)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The distinct categories in the cart that this gateway may NOT charge.
     * Empty means the whole cart is payable on the gateway.
     *
     * @param  iterable<ComplianceCategory>  $categories
     * @return list<ComplianceCategory>
     */
    public function deniedFor(iterable $categories, string $gateway, ?string $currency = null): array
    {
        $denied = [];

        foreach ($categories as $category) {
            if (! $this->allows($category, $gateway, $currency)) {
                // Key by value so a cart of many identical items reports once.
                $denied[$category->value] = $category;
            }
        }

        return array_values($denied);
    }

    /**
     * The categories that BLOCK this payment from proceeding: the denied set
     * when enforcing, and always empty in shadow mode (where the denial is
     * logged instead). The three choke points call this and decide how to
     * surface a non-empty result.
     *
     * @param  iterable<ComplianceCategory>  $categories
     * @param  array<string, mixed>  $context  extra fields for the shadow-mode log line
     * @return list<ComplianceCategory>
     */
    public function blockers(iterable $categories, string $gateway, ?string $currency = null, array $context = []): array
    {
        $denied = $this->deniedFor($categories, $gateway, $currency);

        if ($denied === []) {
            return [];
        }

        if (! $this->enforcementEnabled()) {
            Log::info('payment_eligibility.shadow_block', array_merge([
                'gateway' => $this->normaliseGateway($gateway),
                'currency' => $currency,
                'categories' => array_map(static fn (ComplianceCategory $c): string => $c->value, $denied),
            ], $context));

            return [];
        }

        return $denied;
    }

    /**
     * Every gateway that could charge the WHOLE cart (approves every category
     * in it). Feeds tile filtering and, later, category-aware routing. Returns
     * gateway keys from PaymentGateway. Currency is accepted for the future
     * currency-scoped matrix and ignored today.
     *
     * @param  iterable<ComplianceCategory>  $categories
     * @return list<string>
     */
    public function eligibleGatewaysForCart(iterable $categories, ?string $currency = null): array
    {
        $categories = is_array($categories) ? $categories : iterator_to_array($categories);

        return array_values(array_filter(
            array_map(static fn (PaymentGateway $gateway): string => $gateway->value, PaymentGateway::cases()),
            fn (string $gateway): bool => $this->allowsAll($categories, $gateway, $currency),
        ));
    }

    // ---------------------------------------------------------------------
    // Category-aware routing. The router (FiatGatewayRouter) supplies the
    // ordered list of gateways that CAN serve a method/currency; these methods
    // filter that list down to the ones APPROVED for the cart, so a category
    // one gateway does not approve still routes to a backup that does, rather
    // than being denied and losing the sale.
    //
    // All of them fall back to plain capability order in shadow mode, so routing
    // is unchanged until enforcement is switched on.
    // ---------------------------------------------------------------------

    /**
     * The gateway that should charge this method in this currency for a cart of
     * these categories, or null when no live, capable gateway is also approved.
     *
     * @param  iterable<ComplianceCategory>  $categories
     */
    public function routeGateway(string $method, string $currency, iterable $categories): ?string
    {
        $candidates = FiatGatewayRouter::candidates($method, $currency);

        if (! $this->enforcementEnabled()) {
            return $candidates[0] ?? null;
        }

        $categories = $this->toArray($categories);

        foreach ($candidates as $gateway) {
            if ($this->allowsAll($categories, $gateway, $currency)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * The provisional fiat gateway to stamp on a fresh checkout of these
     * categories, before a method is chosen, or null when no live fiat gateway
     * is approved for them.
     *
     * @param  iterable<ComplianceCategory>  $categories
     */
    public function defaultGatewayFor(iterable $categories, ?string $currency = null): ?string
    {
        $live = FiatGatewayRouter::liveFiatGateways();

        if (! $this->enforcementEnabled()) {
            return $live[0] ?? null;
        }

        $categories = $this->toArray($categories);

        foreach ($live as $gateway) {
            if ($this->allowsAll($categories, $gateway, $currency)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Resolve the provisional fiat gateway for these categories, or throw the
     * right refusal: a plain outage when no fiat gateway is live at all, or a
     * category refusal when gateways are live but none is approved. Used by the
     * order-placement and wallet-funding entry points.
     *
     * @param  iterable<ComplianceCategory>  $categories
     *
     * @throws GatewayDisabledException
     * @throws PaymentCategoryIneligibleException
     */
    public function requireFiatGatewayFor(iterable $categories, ?string $currency = null): string
    {
        $categories = $this->toArray($categories);

        $gateway = $this->defaultGatewayFor($categories, $currency);

        if ($gateway !== null) {
            return $gateway;
        }

        // No approved fiat gateway. Distinguish a real outage (nothing live)
        // from a category refusal (live, but none approved for this cart).
        $fallback = FiatGatewayRouter::defaultGateway();

        if ($fallback === null) {
            throw new GatewayDisabledException('fiat');
        }

        throw new PaymentCategoryIneligibleException(
            $this->deniedFor($categories, $fallback, $currency),
            $fallback,
        );
    }

    /**
     * The checkout method keys that this cart's categories make ineligible,
     * keyed by currency, mirroring FiatGatewayRouter::unavailableFiatMethodsByCurrency()
     * so the checkout greys them through the exact same mechanism as the gateway
     * kill switches. A method is ineligible when no gateway that could serve it
     * (in that currency) is approved for every category in the cart.
     *
     * Returns an empty map in shadow mode, so the tiles are unchanged until
     * enforcement is deliberately switched on.
     *
     * @param  iterable<ComplianceCategory>  $categories
     * @return array<string, list<string>>
     */
    public function ineligibleMethodsByCurrency(iterable $categories): array
    {
        if (! $this->enforcementEnabled()) {
            return [];
        }

        $categories = $this->toArray($categories);

        if ($categories === []) {
            return [];
        }

        $map = [];

        foreach ([...SupportedPaymentMethods::knownCurrencies(), '__fallback'] as $currency) {
            // '__fallback' stands for any currency without an explicit list; it
            // is served by Flutterwave alone, matching FiatGatewayRouter.
            $probe = $currency === '__fallback' ? '???' : $currency;

            $map[$currency] = array_values(array_filter(
                SupportedPaymentMethods::forCurrency($probe),
                fn (string $method): bool => ! $this->methodEligibleForCart($method, $categories, $probe),
            ));
        }

        return $map;
    }

    /**
     * Can any gateway that serves this method (in this currency) charge the
     * whole cart? Used only for tile filtering.
     *
     * @param  list<ComplianceCategory>  $categories
     */
    private function methodEligibleForCart(string $method, array $categories, string $currency): bool
    {
        foreach ($this->gatewaysServingMethod($method, $currency) as $gateway) {
            if ($this->allowsAll($categories, $gateway, $currency)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gateways capable of charging a checkout method in a currency, flags
     * aside. Fiat methods defer to FiatGatewayRouter's capability matrix; crypto
     * and wallet each have a single fixed gateway.
     *
     * @return list<string>
     */
    private function gatewaysServingMethod(string $method, string $currency): array
    {
        if (array_key_exists($method, FiatGatewayRouter::METHOD_PRIORITY)) {
            return array_values(array_filter(
                FiatGatewayRouter::METHOD_PRIORITY[$method],
                fn (string $gateway): bool => FiatGatewayRouter::supports($gateway, $method, $currency),
            ));
        }

        return match ($method) {
            'crypto' => ['nowpayments'],
            'wallet' => ['wallet'],
            default => [],
        };
    }

    /**
     * @param  iterable<ComplianceCategory>  $categories
     * @return list<ComplianceCategory>
     */
    private function toArray(iterable $categories): array
    {
        return array_values(is_array($categories) ? $categories : iterator_to_array($categories));
    }

    /**
     * Canonicalise a checkout-vocabulary gateway to a matrix key. The checkout
     * calls the crypto rail 'crypto'; the matrix (and PaymentGateway) name it
     * 'nowpayments'. Everything else already matches.
     */
    private function normaliseGateway(string $gateway): string
    {
        return $gateway === 'crypto' ? 'nowpayments' : $gateway;
    }
}
