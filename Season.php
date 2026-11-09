<?php

namespace Omnistay\Local;

use Omnistay\Exception\InvalidArgumentException;
use Omnistay\Model\Money;
use Omnistay\Model\Stay;

/**
 * A stretch of the year priced apart: the nights from $from to $to (both
 * included), their price, and the minimum stay that starts in it. When two
 * overlap, the one listed later wins (a host lists the general season
 * first, the holiday weeks after).
 */
final readonly class Season
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    public function __construct(
        \DateTimeInterface|string $from,
        \DateTimeInterface|string $to,
        public Money $nightly,
        public ?int $minStay = null,
        public ?string $name = null,
    ) {
        $this->from = Stay::day($from);
        $this->to = Stay::day($to);
        if ($this->to < $this->from) {
            throw new InvalidArgumentException(\sprintf('A season ends after it starts: %s to %s.', $this->from->format('Y-m-d'), $this->to->format('Y-m-d')));
        }
    }

    public function covers(\DateTimeImmutable $night): bool
    {
        return $night >= $this->from && $night <= $this->to;
    }
}
