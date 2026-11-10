<?php

namespace Omnistay\Local;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistay\AvailabilityInterface;
use Omnistay\BookInterface;
use Omnistay\CalendarInterface;
use Omnistay\CancelInterface;
use Omnistay\Capabilities;
use Omnistay\ContentInterface;
use Omnistay\Exception\NotFoundException;
use Omnistay\Exception\UnavailableException;
use Omnistay\Model\Availability;
use Omnistay\Model\Booking;
use Omnistay\Model\BookingRequest;
use Omnistay\Model\BookingStatus;
use Omnistay\Model\CalendarDay;
use Omnistay\Model\Guests;
use Omnistay\Model\Offer;
use Omnistay\Model\Page;
use Omnistay\Model\PaymentTiming;
use Omnistay\Model\Property;
use Omnistay\Model\PropertyKind;
use Omnistay\Model\PropertyRef;
use Omnistay\Model\SearchResult;
use Omnistay\Model\Stay;
use Omnistay\Model\StayQuery;
use Omnistay\ProviderInterface;
use Omnistay\SearchInterface;

/**
 * The site's own listings as a provider: a host's home answers the same
 * contracts as a hotel system - search, availability and offers, its
 * calendar night by night, a booking, a cancellation, its description.
 * The rules are applied here (Check: capacity, minimum and maximum stay,
 * seasons, notice, blocked and booked nights), the prices made here
 * (Quote), the store keeps what is stored and holds nights atomically.
 *
 * A booking made with a payment's reference (the site took the money) is
 * confirmed - or on request when the host reviews each request -; made
 * without one it is pending, its nights held for $holdMinutes while the
 * guest pays, then confirm() or cancel(). An offer's id is
 * "<listing>|<check-in>|<check-out>|<adults>|<children's ages>|<pets>":
 * offer() prices it again from the calendar as it stands.
 *
 * Its usage policy is policy.json, beside this class (POLICY): the site's
 * own data, no third party's terms - no limit, every right; a site may
 * tighten it in its configuration.
 */
final class LocalProvider implements ProviderInterface, SearchInterface, AvailabilityInterface, CalendarInterface, BookInterface, CancelInterface, ContentInterface, DeclaresUsagePolicyInterface
{
    /** The usage policy of a site's own listings. */
    public const POLICY = __DIR__.'/policy.json';

    private static ?UsagePolicy $policy = null;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param ?\Closure(): \DateTimeImmutable $clock */
    public function __construct(
        private readonly ListingStoreInterface $store,
        private readonly string $name = 'local',
        private readonly string $title = 'Homes',
        private readonly float $serviceFee = 0.0,
        private readonly int $holdMinutes = 30,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn () => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true, availability: true, calendar: true, book: true, cancel: true, content: true,
            searchBy: ['place', 'cityCode', 'point'],
            kinds: [PropertyKind::Apartment, PropertyKind::House, PropertyKind::Room, PropertyKind::Cabin, PropertyKind::Guesthouse, PropertyKind::Other],
            pricedSearch: true, keyless: true,
            policy: $this->usagePolicy(),
        );
    }

    public function search(StayQuery $query): Page
    {
        $now = ($this->clock)();
        $found = [];
        foreach ($this->store->listings($query) as $listing) {
            $property = $listing->property;
            $distance = $query->hasPoint() ? $property->location->distanceTo((float) $query->latitude, (float) $query->longitude) : null;
            if (null === $query->stay) {
                $found[] = new SearchResult($property, null, null, true, null, $distance);
                continue;
            }
            $availability = $this->check($listing, $query->stay, $query->guests, $now);
            if ($availability->available && null !== $offer = $availability->cheapest()) {
                $found[] = new SearchResult($property, $offer->total(), $query->stay->count(), true, $offer, $distance);
            }
        }
        $offset = max(0, (int) $query->page);

        return new Page(\array_slice($found, $offset, $query->limit), $offset + $query->limit < \count($found) ? (string) ($offset + $query->limit) : null, \count($found));
    }

    public function availability(PropertyRef $property, Stay $stay, Guests $guests, ?string $currency = null): Availability
    {
        return $this->check($this->listing($property->id), $stay, $guests, ($this->clock)());
    }

    public function offer(string $id, ?string $language = null): ?Offer
    {
        $parts = explode('|', $id);
        if (6 !== \count($parts) || null === $listing = $this->store->listing($parts[0])) {
            return null;
        }
        try {
            $guests = new Guests(max(1, (int) $parts[3]), '' === $parts[4] ? [] : array_map('intval', explode('.', $parts[4])), max(0, (int) $parts[5]));
            $availability = $this->check($listing, Stay::of($parts[1], $parts[2]), $guests, ($this->clock)());
        } catch (\Omnistay\Exception\InvalidArgumentException) {
            return null;
        }

        return $availability->offers[0] ?? null;
    }

    public function calendar(PropertyRef $property, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $listing = $this->listing($property->id);
        $from = Stay::day($from);
        $to = Stay::day($to);
        if ($to <= $from) {
            return [];
        }
        $now = ($this->clock)();
        $today = Stay::day($now);
        $occupied = $this->store->occupied($listing->id(), $from, $to, $now);
        $rules = $listing->rules;
        $days = [];
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $reason = $occupied[$day->format('Y-m-d')] ?? ($day < $today ? 'past' : null);
            $checkIn = null === $reason && (!$rules->checkInDays || \in_array((int) $day->format('N'), $rules->checkInDays, true)) && $day >= $today->modify(\sprintf('+%d days', $rules->advanceNotice));
            $days[] = new CalendarDay($day, null === $reason, $listing->pricing->night($day), Check::minStay($listing, $day), $reason, $checkIn);
        }

        return $days;
    }

    public function book(BookingRequest $request): Booking
    {
        $asked = $request->offer;
        $listing = $this->listing($asked->property->id);
        $now = ($this->clock)();
        $availability = $this->check($listing, $asked->stay, $asked->guests, $now);
        $offer = $availability->offers[0] ?? throw UnavailableException::because($availability->reason ?? 'booked', (string) $asked->property, (string) $asked->stay);
        $status = null === $request->paymentReference ? BookingStatus::Pending : ($listing->rules->instant ? BookingStatus::Confirmed : BookingStatus::OnRequest);
        $reservation = $this->store->hold(new Reservation(
            $listing->id(), $offer->stay, $offer->guests, $offer->rate, $status, $offer->cancellation, $request->travellers,
            clientReference: $request->clientReference, message: $request->message, createdAt: $now,
            expiresAt: BookingStatus::Pending === $status ? $now->modify(\sprintf('+%d minutes', $this->holdMinutes)) : null,
            paymentReference: $request->paymentReference,
        ), $now);

        return $this->booking((string) $reservation->reference) ?? throw new NotFoundException('The store lost the reservation it held.');
    }

    /** A pending booking paid: confirmed, or on request when the host reviews each request. */
    public function confirm(string $reference): Booking
    {
        $reservation = $this->store->reservation($reference) ?? throw new NotFoundException(\sprintf('No booking "%s".', $reference));
        $status = $this->listing($reservation->listing)->rules->instant ? BookingStatus::Confirmed : BookingStatus::OnRequest;
        $this->store->update($reference, $status);

        return $this->booking($reference) ?? throw new NotFoundException(\sprintf('No booking "%s".', $reference));
    }

    public function booking(string $reference): ?Booking
    {
        $reservation = $this->store->reservation($reference);
        if (null === $reservation) {
            return null;
        }
        $status = BookingStatus::Pending === $reservation->status && !$reservation->holds(($this->clock)()) ? BookingStatus::Cancelled : $reservation->status;

        return new Booking($reference, $status, new PropertyRef($this->name, $reservation->listing), $reservation->stay, $reservation->guests, $reservation->rate->total, [$reference], $reservation->travellers, null, $reservation->cancellation, $reservation->createdAt, $reservation->clientReference);
    }

    public function cancel(string $reference, ?string $reason = null): Booking
    {
        $reservation = $this->store->reservation($reference) ?? throw new NotFoundException(\sprintf('No booking "%s".', $reference));
        if (BookingStatus::Cancelled !== $reservation->status) {
            $this->store->update($reference, BookingStatus::Cancelled);
        }

        return $this->booking($reference) ?? throw new NotFoundException(\sprintf('No booking "%s".', $reference));
    }

    public function property(PropertyRef $property, ?string $language = null): ?Property
    {
        return $this->store->listing($property->id)?->property;
    }

    public function properties(array $properties, ?string $language = null): array
    {
        return array_values(array_filter(array_map(fn (PropertyRef $ref) => $this->property($ref, $language), $properties)));
    }

    private function listing(string $id): Listing
    {
        return $this->store->listing($id) ?? throw new NotFoundException(\sprintf('No listing "%s".', $id));
    }

    private function check(Listing $listing, Stay $stay, Guests $guests, \DateTimeImmutable $now): Availability
    {
        $ref = new PropertyRef($this->name, $listing->id());
        $refusal = Check::refusal($listing, $stay, $guests, $this->store->occupied($listing->id(), $stay->checkIn, $stay->checkOut, $now), $now);
        if (null !== $refusal) {
            return new Availability($ref, $stay, $guests, false, reason: $refusal, minStay: Check::minStay($listing, $stay->checkIn));
        }
        $id = implode('|', [$listing->id(), $stay->checkIn->format('Y-m-d'), $stay->checkOut->format('Y-m-d'), $guests->adults, implode('.', $guests->childAges), $guests->pets]);
        $offer = new Offer($id, $ref, $stay, $guests, Quote::rate($listing, $stay, $guests, $this->serviceFee), $listing->rules->cancellation->policy($stay), $listing->property->roomTypes[0] ?? null, payment: PaymentTiming::Now, instant: $listing->rules->instant);

        return new Availability($ref, $stay, $guests, true, [$offer], minStay: Check::minStay($listing, $stay->checkIn));
    }
}
