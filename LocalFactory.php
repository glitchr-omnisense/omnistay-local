<?php

namespace Omnistay\Local;

use Omnistay\Config;
use Omnistay\ProviderFactory;
use Omnistay\ProviderInterface;

/**
 * "local": the site's own listings, kept in the store given (its database,
 * memory in a test). Options: title ("Homes"), service_fee (the site's fee
 * charged to the guest, in percent of what the host charges: 0), hold_minutes
 * (how long a pending booking holds its nights while the guest pays: 30).
 * No key, no network.
 */
final class LocalFactory extends ProviderFactory
{
    /** @param ?\Closure(): \DateTimeImmutable $clock */
    public function __construct(private readonly ListingStoreInterface $store, private readonly ?\Closure $clock = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnistay.factory_name' => 'local',
            'omnistay.factory_title' => 'Homes',
            'omnistay.required_options' => [],
            'title' => 'Homes',
            'service_fee' => 0,
            'hold_minutes' => 30,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new LocalProvider($this->store, self::name($c), $c->string('title') ?? 'Homes', $c->float('service_fee'), max(1, $c->int('hold_minutes', 30)), $this->clock);
    }
}
