# omnistay/local

[Omnistay](https://github.com/glitchr-omnisense/omnistay)'s provider for a site's own listings:
homes, rooms and cabins rented by their hosts answer the same contracts as a hotel system, so the
site searches hotels and private rentals through one interface.

- **Search** by text, IATA city code or point; with dates, only the listings free then, priced.
- **Prices** line by line (`Quote`): each night (its own price, its season's, the weekend's or the
  base), extra guests, a weekly or monthly discount, cleaning, the site's service fee, a tourist
  tax per adult and night.
- **Rules** (`Check`): capacity, pets, notice, booking window, check-in weekdays, minimum stay
  (longer in a season), maximum stay, the nights blocked by the host or booked.
- **Calendar** night by night; **bookings** held atomically by the store - never twice the same
  night -, pending while the guest pays (and let go when the payment does not come), confirmed or
  on request; **cancellation terms** (flexible, moderate, strict, non-refundable).

```php
$registry = new Omnistay\Registry([new Omnistay\Local\LocalFactory($store)], [
    'homes' => ['factory' => 'local', 'options' => ['service_fee' => 12, 'hold_minutes' => 30]],
]);
```

`$store` is the site's `ListingStoreInterface` (omnibase/booking keeps it in Doctrine);
`InMemoryStore` is one in memory. No key, no network.

Its usage policy, [policy.json](policy.json), says what a site's own data is: no third party's
terms, no rate limit, every right, nothing to credit (`verifiedAt` empty: there is nothing to
read). A site may tighten it in its configuration - a limit on bookings a minute,
`'policy' => ['rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['book']]]]` -
held by omnistay's registry. See [docs/index.md](docs/index.md). License: MIT.
