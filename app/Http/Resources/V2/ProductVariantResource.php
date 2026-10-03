<?php

namespace App\Http\Resources\V2;

use App\Domain\Catalog\Services\ApiCatalogPricer;
use App\Models\ProductVariant;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One buyable denomination: a ₦500 airtime top-up, a £25 card, a 5GB bundle.
 *
 * Two money fields, and the difference matters:
 *
 *   face_value  what the RECIPIENT gets, in the product's own currency. A £25
 *               card is worth £25 to whoever redeems it, whatever the buyer is
 *               browsing in, so this is never converted.
 *   price       what the BUYER pays, converted into their display currency, with
 *               the USD settlement figure alongside. NULL for a variable variant,
 *               which has no fixed price: the app asks POST /v2/catalog/quote
 *               for the amount the customer typed.
 *   price_from  for a variable variant, what its MINIMUM amount costs, so a list
 *               can say "from ₦100". Null when it cannot be quoted.
 *
 * `price` comes from the pricing rule chain via ApiCatalogPricer, never from the
 * product_variants.retail_price column. The column is not what checkout charges,
 * and a browse price that disagrees with checkout is the kind of thing a customer
 * screenshots. If the price is missing here, the controller forgot to run the
 * pricer, and that is a bug worth being loud about rather than papering over with
 * a wrong number.
 *
 * No `sku`. It is a supplier-side identifier and for some suppliers it literally
 * begins with their name. The app addresses a variant by `id`.
 *
 * @property ProductVariant $resource
 */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isVariable = (bool) $this->is_variable;

        $priceAtMinimum = $this->getAttribute(ApiCatalogPricer::MINIMUM_PRICE_ATTRIBUTE);

        return [
            'id' => $this->id,

            'face_value' => $this->face_value !== null
                ? ApiMoney::native((float) $this->face_value, (string) ($this->currency ?: 'USD'))
                : null,

            'price' => $isVariable ? null : ApiMoney::fromUsd($this->resolvedUsdPrice()),
            'price_from' => $isVariable && is_numeric($priceAtMinimum)
                ? ApiMoney::fromUsd((float) $priceAtMinimum)
                : null,

            // A variable variant has no fixed face: the customer types an amount
            // inside these bounds, and asks POST /v2/catalog/quote what it costs.
            // The bounds are in the variant's own currency, which is the currency
            // the customer is typing in.
            'is_variable' => $isVariable,
            'min_amount' => $this->min_amount !== null
                ? ApiMoney::native((float) $this->min_amount, (string) ($this->currency ?: 'USD'))
                : null,
            'max_amount' => $this->max_amount !== null
                ? ApiMoney::native((float) $this->max_amount, (string) ($this->currency ?: 'USD'))
                : null,

            // Present only for eSIM plans. Null for everything else rather than
            // absent, so the app can branch on one thing.
            'esim' => $this->esimPlan(),
        ];
    }

    /**
     * The plan details an eSIM needs: allowance, validity, coverage.
     *
     * An ALLOWLIST, deliberately, and this is the one place where being lazy would
     * do real damage. variant.metadata also holds `provider`, the supplier's own
     * package id, and `raw_payload`: the entire upstream response, wholesale cost
     * included. Publishing the blob would name our suppliers on a customer-facing
     * surface, which we never do, and hand anyone who asked our margins and our
     * sourcing.
     *
     * So fields are named one by one. Anything a supplier adds upstream tomorrow
     * stays private until somebody decides otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function esimPlan(): ?array
    {
        $metadata = (array) ($this->metadata ?? []);

        // No plan_type and no validity means this is not an eSIM plan.
        if (! isset($metadata['plan_type']) && ! isset($metadata['validity_days'])) {
            return null;
        }

        return [
            'plan_type' => $metadata['plan_type'] ?? null,
            'data_limit' => $metadata['data_limit'] ?? null,
            'voice_limit' => $metadata['voice_limit'] ?? null,
            'sms_limit' => $metadata['sms_limit'] ?? null,
            'validity_days' => isset($metadata['validity_days']) ? (int) $metadata['validity_days'] : null,
            'supports_data' => (bool) ($metadata['supports_data'] ?? false),
            'supports_voice' => (bool) ($metadata['supports_voice'] ?? false),
            'supports_sms' => (bool) ($metadata['supports_sms'] ?? false),
            'countries' => array_values((array) ($metadata['countries'] ?? [])),
            'coverage_networks' => array_values((array) ($metadata['coverage_networks'] ?? [])),
            'activation_policy' => $metadata['activation_policy'] ?? null,
            'is_rechargeable' => (bool) ($metadata['is_rechargeable'] ?? false),
            // The supplier's own policy notes, shown verbatim because paraphrasing
            // somebody else's terms is how you end up honouring the wrong ones.
            'operator_info' => array_values((array) ($metadata['operator_info'] ?? [])),
            'other_info' => $metadata['other_info'] ?? null,
        ];
    }

    /**
     * The resolved USD price, or a loud failure.
     *
     * Deliberately throws rather than defaulting. A missing annotation means a
     * controller returned variants without running the pricer, and casting that
     * absence to 0.0 would quietly offer the customer a free product. A 500 and a
     * Sentry alert is the cheaper mistake by a wide margin.
     */
    private function resolvedUsdPrice(): float
    {
        $price = $this->getAttribute(ApiCatalogPricer::PRICE_ATTRIBUTE);

        if (! is_numeric($price)) {
            throw new \LogicException(
                'Variant '.$this->id.' was serialised without a resolved price. '
                .'Run ApiCatalogPricer over the variants before returning them.'
            );
        }

        return (float) $price;
    }
}
