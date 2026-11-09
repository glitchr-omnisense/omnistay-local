<?php

namespace Omnistay\Local;

use Omnistay\Model\StayQuery;

/**
 * Where a site keeps its hosts' listings, their calendars and the nights
 * held: its database (omnibase/booking's Doctrine store), memory in a test
 * (InMemoryStore). The provider applies the rules; the store answers what
 * is stored, and makes holding nights atomic.
 */
interface ListingStoreInterface
{
    /**
     * The published listings that may answer the query - near its point, in
     * its place -, the store narrowing as far as it can; the provider checks
     * the rest.
     *
     * @return list<Listing>
     */
    public function listings(StayQuery $query): array;

    /** A listing by its id, published or not; null when there is none. */
    public function listing(string $id): ?Listing;

    /**
     * The nights not free between $from and $to (excluded), by date (Y-m-d):
     * "booked" (a reservation holding it) or "blocked" (the host's calendar).
     *
     * @return array<string, string>
     */
    public function occupied(string $listing, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array;

    /**
     * Holds the reservation's nights and keeps it, its reference given: at
     * once, checking under the store's own exclusion (a lock, a
     * transaction) that no other reservation took one of its nights
     * meanwhile.
     *
     * @throws \Omnistay\Exception\UnavailableException when one is taken
     */
    public function hold(Reservation $reservation, \DateTimeImmutable $now): Reservation;

    public function reservation(string $reference): ?Reservation;

    /** The reservation in a new status (cancelled, confirmed); null when there is none. */
    public function update(string $reference, \Omnistay\Model\BookingStatus $status): ?Reservation;
}
