<?php

namespace App\Enums;

enum TradeStatus: string
{
    case PendingReview = 'pending_review';
    case UnderReview = 'under_review';
    case NeedMoreInfo = 'need_more_info';
    case Approved = 'approved';
    case PayingOut = 'paying_out';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::UnderReview => 'Under Review',
            self::NeedMoreInfo => 'Need More Information',
            self::Approved => 'Approved',
            self::PayingOut => 'Paying Out',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingReview => 'gray',
            self::UnderReview => 'blue',
            self::NeedMoreInfo => 'orange',
            self::Approved => 'green',
            self::PayingOut => 'indigo',
            self::Paid => 'emerald',
            self::Rejected => 'red',
        };
    }
}
