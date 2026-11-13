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

## Install and a first call

```sh
composer require omnistay/local
```

No key and no network: the listings are the site's own. Here one home kept in memory
(`InMemoryStore`; a site keeps them in its database through its own `ListingStoreInterface`),
priced for three nights, then booked: pending while the guest pays, confirmed once the payment's
reference is given.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnistay\Local\{InMemoryStore, Listing, LocalFactory, Pricing};
use Omnistay\Model\{BookingRequest, Guests, Location, Money, Property, PropertyKind, PropertyRef, Stay, Traveller};

$store = new InMemoryStore([new Listing(
    new Property(new PropertyRef('homes', 'L-1'), 'Cabane des Tilleuls', PropertyKind::Cabin, new Location(45.91, 6.14, city: 'Annecy')),
    new Pricing(Money::of(9000, 'EUR'), weekend: Money::of(11000, 'EUR'), cleaning: Money::of(4000, 'EUR')),
)]);
$homes = (new LocalFactory($store))->create(['name' => 'homes', 'service_fee' => 12]);

$stay = Stay::of(date('Y-m-d', strtotime('+30 days')), date('Y-m-d', strtotime('+33 days')));
$offer = $homes->availability(new PropertyRef('homes', 'L-1'), $stay, new Guests(2))->cheapest();
echo 'total ', $offer->total(), "\n";                     // three nights, cleaning, the 12 % service fee
$booking = $homes->book(new BookingRequest($offer, [new Traveller('Alice', 'Example', 'alice@example.org')], paymentReference: 'pay_123'));
echo $booking->status->value, "\n";                      // confirmed
```

A site wires it in its registry beside hotel providers:

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
