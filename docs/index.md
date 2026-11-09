# omnistay/local

## The store

```php
interface ListingStoreInterface
{
    public function listings(StayQuery $query): array;   // published listings that may answer: near the point, in the place
    public function listing(string $id): ?Listing;
    public function occupied(string $listing, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array;  // ['2026-12-24' => 'blocked', ...]
    public function hold(Reservation $reservation, DateTimeImmutable $now): Reservation;  // atomic; UnavailableException when taken
    public function reservation(string $reference): ?Reservation;
    public function update(string $reference, BookingStatus $status): ?Reservation;
}
```

`hold()` is where double booking is refused: it checks the nights and writes the reservation under
the store's own exclusion - a lock, a transaction. A `Listing` is a `Property` (its ref naming the
provider), a `Pricing` and `Rules`.

## Pricing

`new Pricing(nightly, weekend, seasons, nights, cleaning, includedGuests, extraGuest, touristTax,
weeklyDiscount, monthlyDiscount)`: a night costs its own price (`nights`, by date) if set, else
its season's (the last listed season covering it), else the weekend's on Friday and Saturday
nights, else `nightly`. `Quote::rate()` gives the lines, coded for the site's words: `nights`,
`extra_guests`, `weekly_discount` or `monthly_discount`, `cleaning`, `service`, `tourist_tax`.
`Quote::hostShare()` is what the host is owed (neither the service fee nor the tax).

## Rules

`new Rules(maxGuests, minStay, maxStay, advanceNotice, bookingWindow, checkInDays, instant, pets,
cancellation)`. A refusal's reason: `past`, `capacity`, `pets`, `advance`, `window`,
`check_in_day`, `min_stay` (a season's `minStay` when longer), `max_stay`, `booked`, `blocked`.

## Bookings

- `book()` with a payment's reference: confirmed, or on request when `instant` is false;
  without one: pending, its nights held for `hold_minutes`, then `confirm()` or `cancel()`; a
  pending booking past its hold reads as cancelled and lets its nights go.
- `CancellationTerms`: flexible (free until the day before), moderate (five days, then half),
  strict (fourteen days, then half), non-refundable.

## Offers

An offer's id is `<listing>|<check-in>|<check-out>|<adults>|<children's ages, dot-separated>|<pets>`:
`offer()` prices it again from the calendar as it stands.
