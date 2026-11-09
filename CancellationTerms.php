<?php

namespace Omnistay\Local;

use Omnistay\Model\CancellationPolicy;
use Omnistay\Model\Stay;

/**
 * The cancellation terms a host picks for a listing, turned into the
 * family's CancellationPolicy for a stay: free until a number of days
 * before the check-in (at midnight UTC that day), then a share of the
 * total.
 */
enum CancellationTerms: string
{
    /** Free until the day before the check-in, then the whole stay. */
    case Flexible = 'flexible';
    /** Free until five days before, then half. */
    case Moderate = 'moderate';
    /** Free until fourteen days before, then half; the whole stay in the last week. */
    case Strict = 'strict';
    case NonRefundable = 'non_refundable';

    public function policy(Stay $stay): CancellationPolicy
    {
        return match ($this) {
            self::Flexible => new CancellationPolicy(true, $stay->checkIn->modify('-1 day'), penaltyPercent: 100, code: $this->value),
            self::Moderate => new CancellationPolicy(true, $stay->checkIn->modify('-5 days'), penaltyPercent: 50, code: $this->value),
            self::Strict => new CancellationPolicy(true, $stay->checkIn->modify('-14 days'), penaltyPercent: 50, code: $this->value),
            self::NonRefundable => new CancellationPolicy(false, penaltyPercent: 100, code: $this->value),
        };
    }
}
