<?php

namespace App\Domain\Communication\Enums;

/**
 * Queue priority for communications. Maps to dedicated Horizon queues.
 */
enum Priority: string
{
    case Critical = 'critical';  // OTP, security alerts — comm-critical queue
    case High = 'high';          // Wallet, order transactional — comm-transactional queue
    case Normal = 'normal';      // General notifications — comm-transactional queue
    case Low = 'low';            // Marketing, newsletters — comm-marketing queue

    /**
     * Resolve the Horizon queue name for this priority level.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Critical => 'comm-critical',
            self::High, self::Normal => 'comm-transactional',
            self::Low => 'comm-marketing',
        };
    }

    /**
     * Default priority for a given category.
     */
    public static function forCategory(CommunicationCategory $category): self
    {
        $domain = $category->mailDomain();

        return match (true) {
            $domain === MailDomain::Verify => self::Critical,
            $domain === MailDomain::Security => self::Critical,
            $domain === MailDomain::Compliance => self::High,
            $domain === MailDomain::Wallet => self::High,
            $domain === MailDomain::Orders => self::High,
            $domain === MailDomain::Transactions => self::Normal,
            $domain === MailDomain::Notifications => self::Normal,
            $domain === MailDomain::Marketing => self::Low,
            default => self::Normal,
        };
    }
}
