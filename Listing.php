<?php

namespace Omnistay\Local;

use Omnistay\Model\Property;

/**
 * A host's listing as the site stores it: its description (the family's
 * Property, its ref naming this provider), how it is priced, what it
 * accepts.
 */
final readonly class Listing
{
    public function __construct(public Property $property, public Pricing $pricing, public Rules $rules = new Rules())
    {
    }

    public function id(): string
    {
        return $this->property->ref->id;
    }
}
