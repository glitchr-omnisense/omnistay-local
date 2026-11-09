<?php

namespace Omnistay\Local;

use Omnistay\Exception\UnavailableException;
use Omnistay\Model\BookingStatus;
use Omnistay\Model\Stay;
use Omnistay\Model\StayQuery;

/**
 * A store in memory: the tests', the harness's, a demo's. Its listings are
 * found by a word of their name, town or area, their IATA city code, or
 * their distance to the query's point; references are "R-1", "R-2"...
 */
final class InMemoryStore implements ListingStoreInterface
{
    /** @var array<string, Listing> */
    private array $listings = [];

    /** @var array<string, Reservation> */
    private array $reservations = [];

    /**
     * @param list<Listing>               $listings
     * @param array<string, list<string>> $blocked nights (Y-m-d) the hosts blocked, by listing
     */
    public function __construct(array $listings = [], private array $blocked = [])
    {
        foreach ($listings as $listing) {
            $this->listings[$listing->id()] = $listing;
        }
    }

    public function block(string $listing, string ...$nights): void
    {
        $this->blocked[$listing] = array_values(array_unique([...$this->blocked[$listing] ?? [], ...$nights]));
    }

    public function listings(StayQuery $query): array
    {
        return array_values(array_filter($this->listings, static function (Listing $listing) use ($query): bool {
            $location = $listing->property->location;
            $text = mb_strtolower(implode(' ', array_filter([$listing->property->name, $location->city, $location->area])));
            $distance = $query->hasPoint() ? $location->distanceTo((float) $query->latitude, (float) $query->longitude) : null;

            return (null !== $query->place && str_contains($text, mb_strtolower(trim($query->place))))
                || (null !== $query->cityCode && strtoupper($query->cityCode) === $location->cityCode)
                || (null !== $distance && $distance <= $query->radius);
        }));
    }

    public function listing(string $id): ?Listing
    {
        return $this->listings[$id] ?? null;
    }

    public function occupied(string $listing, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
    {
        $occupied = [];
        $range = new Stay($from, $to);
        foreach ($this->blocked[$listing] ?? [] as $night) {
            if ($range->contains(Stay::day($night))) {
                $occupied[$night] = 'blocked';
            }
        }
        foreach ($this->reservations as $reservation) {
            if ($listing === $reservation->listing && $reservation->holds($now)) {
                foreach ($reservation->stay->dates() as $night) {
                    if ($range->contains($night)) {
                        $occupied[$night->format('Y-m-d')] = 'booked';
                    }
                }
            }
        }
        ksort($occupied);

        return $occupied;
    }

    public function hold(Reservation $reservation, \DateTimeImmutable $now): Reservation
    {
        $taken = $this->occupied($reservation->listing, $reservation->stay->checkIn, $reservation->stay->checkOut, $now);
        if ([] !== $taken) {
            throw UnavailableException::because((string) reset($taken), $reservation->listing, (string) $reservation->stay);
        }
        $reference = 'R-'.(\count($this->reservations) + 1);

        return $this->reservations[$reference] = $reservation->with($reference);
    }

    public function reservation(string $reference): ?Reservation
    {
        return $this->reservations[$reference] ?? null;
    }

    public function update(string $reference, BookingStatus $status): ?Reservation
    {
        if (!isset($this->reservations[$reference])) {
            return null;
        }

        return $this->reservations[$reference] = $this->reservations[$reference]->with(status: $status);
    }
}
