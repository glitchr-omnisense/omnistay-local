<?php

namespace Omnistay\Local;

use Omnistay\Model\Charge;
use Omnistay\Model\ChargeKind;
use Omnistay\Model\Guests;
use Omnistay\Model\Money;
use Omnistay\Model\NightRate;
use Omnistay\Model\Rate;
use Omnistay\Model\Stay;

/**
 * What a stay at a listing costs, line by line, each line's $code naming
 * it for the site's words: "nights", "extra_guests", "weekly_discount" or
 * "monthly_discount", "cleaning", "service" (the site's fee, a share of
 * what the host charges), "tourist_tax" (per adult and night). The lines
 * add up to the total.
 */
final class Quote
{
    public static function rate(Listing $listing, Stay $stay, Guests $guests, float $serviceFee = 0.0): Rate
    {
        $pricing = $listing->pricing;
        $currency = $pricing->currency;
        $nights = array_map(static fn (\DateTimeImmutable $night) => new NightRate($night, $pricing->night($night)), $stay->dates());
        $base = Money::zero($currency)->add(...array_map(static fn (NightRate $n) => $n->price, $nights));
        $charges = [new Charge(ChargeKind::Nights, $base, code: 'nights')];

        $hostTotal = $base;
        $extra = max(0, $guests->count() - $pricing->includedGuests);
        if ($extra > 0 && null !== $pricing->extraGuest && !$pricing->extraGuest->isZero()) {
            $fee = $pricing->extraGuest->multiply($extra * $stay->count());
            $charges[] = new Charge(ChargeKind::Other, $fee, code: 'extra_guests');
            $hostTotal = $hostTotal->add($fee);
        }
        $percent = $pricing->discount($stay->count());
        if ($percent > 0) {
            $discount = $hostTotal->percent($percent);
            $charges[] = new Charge(ChargeKind::Discount, Money::of(-$discount->amount, $currency), code: $stay->count() >= 28 && $pricing->monthlyDiscount > 0 ? 'monthly_discount' : 'weekly_discount');
            $hostTotal = $hostTotal->subtract($discount);
        }
        if (null !== $pricing->cleaning && !$pricing->cleaning->isZero()) {
            $charges[] = new Charge(ChargeKind::Cleaning, $pricing->cleaning, code: 'cleaning');
            $hostTotal = $hostTotal->add($pricing->cleaning);
        }
        $total = $hostTotal;
        if ($serviceFee > 0) {
            $service = $hostTotal->percent($serviceFee);
            $charges[] = new Charge(ChargeKind::Service, $service, code: 'service');
            $total = $total->add($service);
        }
        if (null !== $pricing->touristTax && !$pricing->touristTax->isZero()) {
            $tax = $pricing->touristTax->multiply($guests->adults * $stay->count());
            $charges[] = new Charge(ChargeKind::Tax, $tax, code: 'tourist_tax');
            $total = $total->add($tax);
        }

        return new Rate($total, $charges, $nights);
    }

    /** What the host is owed of a rate: everything but the site's service fee and the taxes it collects. */
    public static function hostShare(Rate $rate): Money
    {
        $share = Money::zero($rate->total->currency);
        foreach ($rate->charges as $charge) {
            if (!$charge->included && !\in_array($charge->kind, [ChargeKind::Service, ChargeKind::Tax], true)) {
                $share = $share->add($charge->amount);
            }
        }

        return $share;
    }
}
