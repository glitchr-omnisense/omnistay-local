<?php

namespace Omnistay\Local;

use Omnistay\Model\Guests;
use Omnistay\Model\Stay;

/**
 * Whether a listing takes a stay, by its rules and its calendar: null
 * when it does, else the reason - past, capacity, pets, advance (too
 * soon), window (too far ahead), check_in_day, min_stay, max_stay, then
 * booked or blocked for the first night taken.
 */
final class Check
{
    /** @param array<string, string> $occupied the nights not free, by date */
    public static function refusal(Listing $listing, Stay $stay, Guests $guests, array $occupied, \DateTimeImmutable $now): ?string
    {
        $rules = $listing->rules;
        $today = Stay::day($now);
        if ($stay->checkIn < $today) {
            return 'past';
        }
        if ($guests->count() > $rules->maxGuests) {
            return 'capacity';
        }
        if ($guests->pets > 0 && !$rules->pets) {
            return 'pets';
        }
        if ($stay->checkIn < $today->modify(\sprintf('+%d days', $rules->advanceNotice))) {
            return 'advance';
        }
        if ($stay->checkIn > $today->modify(\sprintf('+%d days', $rules->bookingWindow))) {
            return 'window';
        }
        if ($rules->checkInDays && !\in_array((int) $stay->checkIn->format('N'), $rules->checkInDays, true)) {
            return 'check_in_day';
        }
        if ($stay->count() < self::minStay($listing, $stay->checkIn)) {
            return 'min_stay';
        }
        if (null !== $rules->maxStay && $stay->count() > $rules->maxStay) {
            return 'max_stay';
        }
        foreach ($stay->dates() as $night) {
            if (isset($occupied[$night->format('Y-m-d')])) {
                return $occupied[$night->format('Y-m-d')];
            }
        }

        return null;
    }

    /** The shortest stay that may start on this night: the listing's, or its season's when longer. */
    public static function minStay(Listing $listing, \DateTimeImmutable $checkIn): int
    {
        return max($listing->rules->minStay, $listing->pricing->season($checkIn)->minStay ?? 1);
    }
}
