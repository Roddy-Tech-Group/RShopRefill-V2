<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Shared\Enums\ComplianceCategory;
use Exception;

/**
 * A payment was attempted for a product category that the resolved gateway has
 * not been approved to charge.
 *
 * Thrown (or rendered) pre-charge, so a refused attempt moves no money and, at
 * the order-placement gate, writes zero rows. The message names the category in
 * customer language and frames removal or retry, mirroring the greyed-out tiles
 * on checkout, exactly like GatewayDisabledException.
 */
class PaymentCategoryIneligibleException extends Exception
{
    /**
     * @param  list<ComplianceCategory>  $categories  the categories that blocked the payment
     */
    public function __construct(
        public readonly array $categories,
        public readonly ?string $gateway = null,
    ) {
        parent::__construct(self::messageFor($categories));
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * @param  list<ComplianceCategory>  $categories
     */
    public static function messageFor(array $categories): string
    {
        $labels = array_values(array_unique(array_map(
            static fn (ComplianceCategory $category): string => $category->label(),
            $categories,
        )));

        if ($labels === []) {
            return 'One or more items in your cart cannot be purchased with the available payment methods right now.';
        }

        $names = count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels)
            : $labels[0];

        $subject = count($labels) > 1 ? 'these items' : 'this item';

        return "{$names} cannot be purchased with the available payment methods right now. "
            ."Please remove {$subject} to continue, or check back shortly.";
    }
}
