<?php

namespace Omnistay\Local\Tests;

use Omnistay\Exception\NotFoundException;
use Omnistay\Exception\UnavailableException;
use Omnistay\Local\CancellationTerms;
use Omnistay\Local\InMemoryStore;
use Omnistay\Local\Listing;
use Omnistay\Local\LocalFactory;
use Omnistay\Local\LocalProvider;
use Omnistay\Local\Pricing;
use Omnistay\Local\Quote;
use Omnistay\Local\Rules;
use Omnistay\Local\Season;
use Omnistay\Model\Amenity;
use Omnistay\Model\BookingRequest;
use Omnistay\Model\BookingStatus;
use Omnistay\Model\Charge;
use Omnistay\Model\Guests;
use Omnistay\Model\Location;
use Omnistay\Model\Money;
use Omnistay\Model\Property;
use Omnistay\Model\PropertyKind;
use Omnistay\Model\PropertyRef;
use Omnistay\Model\Stay;
use Omnistay\Model\StayQuery;
use Omnistay\Model\Traveller;
use Omnistay\Registry;
use Omnistay\Testing\FixedProviderFactory;
use PHPUnit\Framework\TestCase;

final class LocalProviderTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-09T10:00:00Z');
        $this->store = new InMemoryStore([
            new Listing(
                new Property(new PropertyRef('homes', 'L-1'), 'Cabane des Tilleuls', PropertyKind::Cabin, new Location(45.91, 6.14, city: 'Annecy', area: 'Talloires', exact: false), amenities: [Amenity::Kitchen, Amenity::Wifi], maxGuests: 4, rating: 4.8, host: 'Inès'),
                new Pricing(Money::of(9000, 'EUR'), weekend: Money::of(11000, 'EUR'), seasons: [
                    new Season('2026-12-19', '2027-01-03', Money::of(14000, 'EUR'), 3, 'Holidays'),
                    new Season('2026-12-31', '2026-12-31', Money::of(20000, 'EUR'), 2, 'New Year\'s Eve'),
                ], nights: ['2026-11-25' => Money::of(7000, 'EUR')], cleaning: Money::of(4000, 'EUR'), includedGuests: 2, extraGuest: Money::of(1500, 'EUR'), touristTax: Money::of(110, 'EUR'), weeklyDiscount: 10),
                new Rules(maxGuests: 4, minStay: 2, maxStay: 21, advanceNotice: 1, bookingWindow: 300, cancellation: CancellationTerms::Moderate),
            ),
            new Listing(
                new Property(new PropertyRef('homes', 'L-2'), 'Studio du Port', PropertyKind::Apartment, new Location(43.70, 7.28, city: 'Nice', cityCode: 'NCE')),
                new Pricing(Money::of(8000, 'EUR')),
                new Rules(maxGuests: 2, checkInDays: [6], instant: false, cancellation: CancellationTerms::Strict),
            ),
        ], ['L-1' => ['2026-11-28']]);
    }

    public function testAStayIsPricedLineByLine(): void
    {
        // Thursday the 19th, then Friday and Saturday at the weekend's price; three guests, one beyond the two included.
        $offer = $this->provider()->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-11-19', '2026-11-22'), new Guests(3))->cheapest();
        self::assertNotNull($offer);

        self::assertSame(['9000', '11000', '11000'], array_map(static fn ($n) => (string) $n->price->amount, $offer->rate->nights));
        self::assertSame([['nights', 31000], ['extra_guests', 4500], ['cleaning', 4000], ['service', 4938], ['tourist_tax', 990]], array_map(static fn (Charge $c) => [$c->code, $c->amount->amount], $offer->rate->charges));
        self::assertTrue($offer->rate->addsUp());
        self::assertSame(45428, $offer->total()->amount);
        self::assertSame(39500, Quote::hostShare($offer->rate)->amount, 'nights, extra guests and cleaning: not the fee nor the tax');
        self::assertSame('2026-11-14', $offer->cancellation->freeUntil?->format('Y-m-d'), 'moderate: five days before');
        self::assertSame(50, $offer->cancellation->penaltyPercent);
    }

    public function testSeasonsANightOfItsOwnAndAWeeksDiscount(): void
    {
        $provider = $this->provider();
        $home = new PropertyRef('homes', 'L-1');

        $one = $provider->availability($home, Stay::of('2026-11-24', '2026-11-26'), new Guests(2))->cheapest();
        self::assertSame([9000, 7000], array_map(static fn ($n) => $n->price->amount, $one?->rate->nights ?? []), 'the 25th has its own price');

        $holidays = $provider->availability($home, Stay::of('2026-12-29', '2027-01-05'), new Guests(2))->cheapest();
        self::assertSame([14000, 14000, 20000, 14000, 14000, 14000, 9000], array_map(static fn ($n) => $n->price->amount, $holidays?->rate->nights ?? []), 'the later season wins on the 31st; the season ends with the night of January 3');
        self::assertSame(['weekly_discount', -9900], [$holidays?->rate->charges[1]->code, $holidays?->rate->charges[1]->amount->amount]);
    }

    public function testTheRulesSayWhyNot(): void
    {
        $provider = $this->provider();
        $home = new PropertyRef('homes', 'L-1');
        $why = static fn (string $in, string $out, Guests $guests = new Guests(2)) => $provider->availability($home, Stay::of($in, $out), $guests);

        self::assertSame('past', $why('2026-11-01', '2026-11-04')->reason);
        self::assertSame('advance', $why('2026-11-09', '2026-11-12')->reason, 'a day\'s notice');
        self::assertSame('window', $why('2027-10-01', '2027-10-04')->reason);
        self::assertSame('capacity', $why('2026-11-19', '2026-11-22', new Guests(3, [5, 9]))->reason);
        self::assertSame('pets', $why('2026-11-19', '2026-11-22', new Guests(2, pets: 1))->reason);
        self::assertSame('min_stay', $why('2026-11-19', '2026-11-20')->reason);
        $holidays = $why('2026-12-20', '2026-12-22');
        self::assertSame(['min_stay', 3], [$holidays->reason, $holidays->minStay], 'the season asks three nights');
        self::assertSame('max_stay', $why('2026-11-12', '2026-12-10')->reason);
        self::assertSame('blocked', $why('2026-11-27', '2026-11-30')->reason);
        self::assertSame('check_in_day', $provider->availability(new PropertyRef('homes', 'L-2'), Stay::of('2026-11-19', '2026-11-21'), new Guests(1))->reason, 'Saturdays only');
    }

    public function testTheSameNightsAreNeverBookedTwice(): void
    {
        $provider = $this->provider();
        $offer = $provider->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-02', '2026-12-05'), new Guests(2))->cheapest();
        self::assertNotNull($offer);

        $first = $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example', 'alice@example.org')], paymentReference: 'cs_test_1', clientReference: 'S-1'));
        self::assertSame(['R-1', 'confirmed', 'S-1'], [$first->reference, $first->status->value, $first->clientReference]);

        $overlap = $provider->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-04', '2026-12-07'), new Guests(2));
        self::assertSame([false, 'booked'], [$overlap->available, $overlap->reason]);
        try {
            $provider->book(new BookingRequest($offer, [new Traveller('Bob', 'Example')], paymentReference: 'cs_test_2'));
            self::fail('booked twice');
        } catch (UnavailableException $e) {
            self::assertSame('booked', $e->reason);
        }
        self::assertTrue($provider->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-05', '2026-12-07'), new Guests(2))->available, 'the check-out day is free for the next guest');
    }

    public function testNightsBlockedAfterTheOfferAreRefused(): void
    {
        $provider = $this->provider();
        $offer = $provider->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-02', '2026-12-05'), new Guests(2))->cheapest();
        self::assertNotNull($offer);
        $this->store->block('L-1', '2026-12-03');

        $this->expectException(UnavailableException::class);
        $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example')]));
    }

    /** Two guests checked free at the same moment: the store, holding under its own exclusion, takes the first and refuses the second. */
    public function testTheStoreHoldsNightsOnceWhateverTheCheckSaid(): void
    {
        $offer = $this->provider()->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-02', '2026-12-05'), new Guests(2))->cheapest();
        self::assertNotNull($offer);
        $reservation = static fn (string $name) => new \Omnistay\Local\Reservation('L-1', $offer->stay, $offer->guests, $offer->rate, BookingStatus::Confirmed, $offer->cancellation, [new Traveller($name, 'Example')]);

        self::assertSame('R-1', $this->store->hold($reservation('Alice'), $this->now)->reference);
        $this->expectException(UnavailableException::class);
        $this->expectExceptionMessage('L-1 is not available for 2026-12-02/2026-12-05 (booked).');
        $this->store->hold($reservation('Bob'), $this->now);
    }

    public function testAPendingBookingHoldsItsNightsUntilPaidOrExpired(): void
    {
        $provider = $this->provider();
        $home = new PropertyRef('homes', 'L-1');
        $offer = $provider->availability($home, Stay::of('2026-12-02', '2026-12-05'), new Guests(2))->cheapest();
        self::assertNotNull($offer);

        $pending = $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example')]));
        self::assertSame(BookingStatus::Pending, $pending->status);
        self::assertFalse($provider->availability($home, $offer->stay, new Guests(2))->available);

        $this->now = $this->now->modify('+31 minutes');
        self::assertSame(BookingStatus::Cancelled, $provider->booking('R-1')?->status, 'expired');
        self::assertTrue($provider->availability($home, $offer->stay, new Guests(2))->available, 'its nights let go');

        $paid = $provider->book(new BookingRequest($offer, [new Traveller('Bob', 'Example')]));
        self::assertSame(BookingStatus::Confirmed, $provider->confirm($paid->reference)->status);
    }

    public function testAHostWhoReviewsRequestsGetsThemOnRequest(): void
    {
        $provider = $this->provider();
        $offer = $provider->availability(new PropertyRef('homes', 'L-2'), Stay::of('2026-11-21', '2026-11-23'), new Guests(1))->cheapest();
        self::assertNotNull($offer);
        self::assertFalse($offer->instant);

        self::assertSame(BookingStatus::OnRequest, $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example')], paymentReference: 'cs_test_1'))->status);
    }

    public function testACancellationLetsTheNightsGo(): void
    {
        $provider = $this->provider();
        $offer = $provider->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-02', '2026-12-05'), new Guests(2))->cheapest();
        self::assertNotNull($offer);
        $booking = $provider->book(new BookingRequest($offer, [new Traveller('Alice', 'Example')], paymentReference: 'cs_test_1'));

        self::assertSame(BookingStatus::Cancelled, $provider->cancel($booking->reference)->status);
        self::assertSame(BookingStatus::Cancelled, $provider->cancel($booking->reference)->status, 'twice: as it is');
        self::assertTrue($provider->availability(new PropertyRef('homes', 'L-1'), $offer->stay, new Guests(2))->available);

        $this->expectException(NotFoundException::class);
        $provider->cancel('R-404');
    }

    public function testTheCalendarNightByNight(): void
    {
        $days = $this->provider()->calendar(new PropertyRef('homes', 'L-1'), new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2026-11-12'));

        self::assertSame([
            ['2026-11-08', false, 'past', false, 9000, 2],
            ['2026-11-09', true, null, false, 9000, 2],
            ['2026-11-10', true, null, true, 9000, 2],
            ['2026-11-11', true, null, true, 9000, 2],
        ], array_map(static fn ($d) => [$d->date->format('Y-m-d'), $d->available, $d->reason, $d->checkInAllowed, $d->price?->amount, $d->minStay], $days));
    }

    public function testAnOfferIsPricedAgainFromItsId(): void
    {
        $provider = $this->provider();
        $offer = $provider->availability(new PropertyRef('homes', 'L-1'), Stay::of('2026-12-02', '2026-12-05'), new Guests(2, [7]))->cheapest();
        self::assertNotNull($offer);

        self::assertSame('L-1|2026-12-02|2026-12-05|2|7|0', $offer->id);
        self::assertSame($offer->total()->amount, $provider->offer($offer->id)?->total()->amount);
        self::assertNull($provider->offer('L-1|2026-11-28|2026-11-30|2||0'), 'blocked');
        self::assertNull($provider->offer('nonsense'));
    }

    public function testHotelsAndHomesInOneSearch(): void
    {
        $registry = new Registry([
            new LocalFactory($this->store, fn () => $this->now),
            new FixedProviderFactory([new Property(new PropertyRef('hotels', 'H-1'), 'Hôtel du Lac', PropertyKind::Hotel, new Location(45.90, 6.13, city: 'Annecy'))], ['H-1' => Money::of(16000, 'EUR')]),
        ], ['homes' => ['factory' => 'local', 'options' => ['service_fee' => 12]], 'hotels' => ['factory' => 'fixed']]);

        $results = $registry->searchAll(new StayQuery(Stay::of('2026-12-02', '2026-12-05'), new Guests(2), latitude: 45.9, longitude: 6.13, radius: 15));

        self::assertSame(['homes:L-1', 'hotels:H-1'], array_map(static fn ($r) => (string) $r->property->ref, $results->items));
        self::assertSame([true, true], array_map(static fn ($r) => $r->available, $results->items));
        self::assertSame(['place', 'cityCode', 'point'], $registry->get('homes')->capabilities()->searchBy);

        $nice = $registry->searchAll(new StayQuery(cityCode: 'nce'));
        self::assertSame(['homes:L-2'], array_map(static fn ($r) => (string) $r->property->ref, $nice->items), 'no dates: every listing there, unpriced');
    }

    public function testASearchWithDatesLeavesTheTakenOut(): void
    {
        $page = $this->provider()->search(new StayQuery(Stay::of('2026-11-27', '2026-11-30'), place: 'talloires'));

        self::assertSame([], $page->items, 'the 28th is blocked');
    }

    private function provider(): LocalProvider
    {
        return new LocalProvider($this->store, 'homes', 'Homes', 12.5, 30, fn () => $this->now);
    }
}
