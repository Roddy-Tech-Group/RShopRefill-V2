<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\MailDomain;

/**
 * Resolves the sender address and display name for a communication
 * based on its category.
 *
 * All sender addresses are on the verified rshoprefill.com domain.
 * Every email carries Reply-To: support@rshoprefill.com.
 */
class SenderResolver
{
    /**
     * Resolve the sender mailbox for a category.
     */
    public function resolve(CommunicationCategory $category): MailDomain
    {
        return $category->mailDomain();
    }

    /**
     * Get the "From" header value for a category.
     * Example: "RshopRefill Security <security@rshoprefill.com>"
     */
    public function fromHeader(CommunicationCategory $category): string
    {
        return $category->mailDomain()->fromHeader();
    }

    /**
     * Get the sender email address for a category.
     */
    public function senderAddress(CommunicationCategory $category): string
    {
        return $category->mailDomain()->senderAddress();
    }

    /**
     * The universal Reply-To address.
     */
    public function replyTo(): string
    {
        return MailDomain::replyTo();
    }
}
