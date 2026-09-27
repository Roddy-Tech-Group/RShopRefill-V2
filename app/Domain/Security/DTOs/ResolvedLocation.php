<?php

namespace App\Domain\Security\DTOs;

/**
 * Where an IP address resolved to, normalised.
 *
 * `countryCode` is the field anything comparing two logins must use. Country
 * NAMES differ between providers for the same place ("The Netherlands" from
 * ip-api, "Netherlands" from MaxMind), so a rule that compares names would
 * read a provider swap as a customer moving country. The ISO 3166-1 alpha-2
 * code does not drift, so the code decides and the name is only ever shown.
 *
 * An instance always carries a country code; when a lookup cannot determine
 * one, callers get null instead of a half-filled object.
 */
readonly class ResolvedLocation
{
    public function __construct(
        public string $countryCode,
        public ?string $countryName = null,
        public ?string $city = null,
        public ?string $timezone = null,
    ) {}

    /**
     * @param  array{country_code: string, country_name: ?string, city: ?string, timezone: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            countryCode: $data['country_code'],
            countryName: $data['country_name'] ?? null,
            city: $data['city'] ?? null,
            timezone: $data['timezone'] ?? null,
        );
    }

    /**
     * @return array{country_code: string, country_name: ?string, city: ?string, timezone: ?string}
     */
    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'city' => $this->city,
            'timezone' => $this->timezone,
        ];
    }
}
