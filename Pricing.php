<?php

namespace Omnistay\Local;

use Omnistay\Exception\InvalidArgumentException;
use Omnistay\Model\Money;

/**
 * How a host prices a listing: a price per night, another for Friday and
 * Saturday nights, seasons over them, a price set for one night over
 * everything ($nights, from the calendar); a cleaning fee per stay, a fee
 * per night for each guest beyond those included, a tourist tax per adult
 * and night (as French towns levy it), discounts from a week and from four
 * weeks. Every amount is in $currency.
 */
final readonly class Pricing
{
    public string $currency;

    /**
     * @param list<Season>         $seasons
     * @param array<string, Money> $nights a night's own price, by date (Y-m-d)
     */
    public function __construct(
        public Money $nightly,
        public ?Money $weekend = null,
        public array $seasons = [],
        public array $nights = [],
        public ?Money $cleaning = null,
        public int $includedGuests = 2,
        public ?Money $extraGuest = null,
        public ?Money $touristTax = null,
        public int $weeklyDiscount = 0,
        public int $monthlyDiscount = 0,
    ) {
        $this->currency = $nightly->currency;
        foreach ([$weekend, $cleaning, $extraGuest, $touristTax, ...array_map(static fn (Season $s) => $s->nightly, $seasons), ...array_values($nights)] as $money) {
            if (null !== $money && $money->currency !== $this->currency) {
                throw new InvalidArgumentException(\sprintf('A listing is priced in one currency: %s, not %s.', $this->currency, $money->currency));
            }
        }
        if ($weeklyDiscount < 0 || $weeklyDiscount > 90 || $monthlyDiscount < 0 || $monthlyDiscount > 90) {
            throw new InvalidArgumentException('A discount is 0 to 90 %.');
        }
    }

    /** The price of the night that starts on $night: its own, else its season's, else the weekend's or the base. */
    public function night(\DateTimeImmutable $night): Money
    {
        if (isset($this->nights[$night->format('Y-m-d')])) {
            return $this->nights[$night->format('Y-m-d')];
        }
        foreach (array_reverse($this->seasons) as $season) {
            if ($season->covers($night)) {
                return $season->nightly;
            }
        }

        return null !== $this->weekend && \in_array((int) $night->format('N'), [5, 6], true) ? $this->weekend : $this->nightly;
    }

    /** The season a night falls in, the last listed winning. */
    public function season(\DateTimeImmutable $night): ?Season
    {
        foreach (array_reverse($this->seasons) as $season) {
            if ($season->covers($night)) {
                return $season;
            }
        }

        return null;
    }

    /** The discount, in percent, for a stay of this many nights. */
    public function discount(int $nights): int
    {
        return $nights >= 28 && $this->monthlyDiscount > 0 ? $this->monthlyDiscount : ($nights >= 7 ? $this->weeklyDiscount : 0);
    }
}
