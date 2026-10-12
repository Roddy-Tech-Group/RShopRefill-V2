<?php

namespace App\Domain\Kyc\DTOs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Who the identity document says the customer is, as the provider read it.
 * Carried on an approved verdict and stored on the attempt it approved.
 *
 * The ID number is not part of it on purpose: the provider holds the document
 * under our job reference, and nothing on our side needs the number.
 */
final readonly class VerifiedIdentity
{
    /**
     * Placeholders Smile writes where a document has no value, seen in sandbox
     * job results ("Not Available") and in its documented samples.
     */
    private const EMPTY_VALUES = ['', 'not available', 'none', 'n/a', 'null'];

    public function __construct(
        public ?string $legalName,
        public ?string $dateOfBirth,
        public ?string $idType,
        public ?string $idCountry,
        public ?string $idExpiresOn,
    ) {}

    /**
     * Read a Smile result, the same keys whether it came as a callback or a
     * job-status answer. Null when it names nobody: a result with neither a
     * name nor a date of birth identifies no one.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromSmile(array $result): ?self
    {
        $name = self::text($result['FullName'] ?? null)
            ?? self::joinedName($result);

        $identity = new self(
            legalName: $name !== null ? Str::of($name)->squish()->limit(200, '')->toString() : null,
            dateOfBirth: self::date($result['DOB'] ?? null),
            idType: ($type = self::text($result['IDType'] ?? null)) !== null ? Str::upper(Str::limit($type, 40, '')) : null,
            idCountry: self::country($result['Country'] ?? null),
            idExpiresOn: self::date($result['ExpirationDate'] ?? null),
        );

        return $identity->legalName === null && $identity->dateOfBirth === null ? null : $identity;
    }

    /**
     * The attributes this identity sets on a kyc_verifications row.
     *
     * @return array{legal_name: ?string, date_of_birth: ?string, id_type: ?string, id_country: ?string, id_expires_on: ?string}
     */
    public function attributes(): array
    {
        return [
            'legal_name' => $this->legalName,
            'date_of_birth' => $this->dateOfBirth,
            'id_type' => $this->idType,
            'id_country' => $this->idCountry,
            'id_expires_on' => $this->idExpiresOn,
        ];
    }

    /** @param  array<string, mixed>  $result */
    private static function joinedName(array $result): ?string
    {
        $parts = array_filter([
            self::text($result['FirstName'] ?? null),
            self::text($result['OtherName'] ?? null),
            self::text($result['LastName'] ?? null),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array(Str::lower($value), self::EMPTY_VALUES, true) ? null : $value;
    }

    /** A calendar date as Y-m-d, or null for anything that is not one. */
    private static function date(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', substr($value, 0, 10));
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === substr($value, 0, 10) ? $date->format('Y-m-d') : null;
    }

    private static function country(mixed $value): ?string
    {
        $value = self::text($value);

        return $value !== null && preg_match('/^[A-Za-z]{2}$/', $value) ? Str::upper($value) : null;
    }
}
