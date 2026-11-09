<?php

namespace Omnistay\Local;

use Omnistay\Model\BookingStatus;
use Omnistay\Model\CancellationPolicy;
use Omnistay\Model\Guests;
use Omnistay\Model\Rate;
use Omnistay\Model\Stay;
use Omnistay\Model\Traveller;

/**
 * Nights held on a listing: what the store keeps of a booking. $reference
 * is the store's (null until held); a pending one holds its nights until
 * $expiresAt (a payment not finished lets them go).
 */
final readonly class Reservation
{
    /** @param list<Traveller> $travellers */
    public function __construct(
        public string $listing,
        public Stay $stay,
        public Guests $guests,
        public Rate $rate,
        public BookingStatus $status,
        public CancellationPolicy $cancellation,
        public array $travellers = [],
        public ?string $reference = null,
        public ?string $clientReference = null,
        public ?string $message = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?string $paymentReference = null,
    ) {
    }

    /** Whether it holds its nights at this moment. */
    public function holds(\DateTimeImmutable $now): bool
    {
        return $this->status->holds() && !(BookingStatus::Pending === $this->status && null !== $this->expiresAt && $this->expiresAt <= $now);
    }

    public function with(?string $reference = null, ?BookingStatus $status = null): self
    {
        return new self($this->listing, $this->stay, $this->guests, $this->rate, $status ?? $this->status, $this->cancellation, $this->travellers, $reference ?? $this->reference, $this->clientReference, $this->message, $this->createdAt, $this->expiresAt, $this->paymentReference);
    }
}
