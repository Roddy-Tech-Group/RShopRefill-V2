<?php

namespace App\Domain\Catalog\Services;

interface CatalogNormalizerInterface
{
    /**
     * Take a raw provider payload item, group it by brand/country,
     * and upsert into the products and product_variants tables.
     *
     * Returns the provider_offer_id of every variant the item saved, so the
     * sync can record that the supplier still lists those offers. An item
     * that is deliberately skipped (e.g. a blocklisted brand) returns [].
     *
     * @return list<string>
     */
    public function normalizeAndSave(array $rawItem, string $providerName): array;
}
