<?php

namespace Omnistay\Local;

/**
 * What a host accepts: how many guests, the shortest and longest stay
 * (a season may ask more), how many days ahead a booking must come and
 * how far ahead the calendar opens, the weekdays a stay may start on (1 for
 * Monday ... 7 for Sunday; none: any), whether a guest books at once or
 * asks first, and pets.
 */
final readonly class Rules
{
    /** @param list<int> $checkInDays */
    public function __construct(
        public int $maxGuests = 2,
        public int $minStay = 1,
        public ?int $maxStay = null,
        public int $advanceNotice = 0,
        public int $bookingWindow = 365,
        public array $checkInDays = [],
        public bool $instant = true,
        public bool $pets = false,
        public CancellationTerms $cancellation = CancellationTerms::Moderate,
    ) {
    }
}
