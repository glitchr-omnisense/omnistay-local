<?php

namespace Omnistay\Local\Tests;

use Omnisense\Contracts\Policy\Permission;
use Omnistay\Exception\UsageLimitReachedException;
use Omnistay\Local\InMemoryStore;
use Omnistay\Local\Listing;
use Omnistay\Local\LocalFactory;
use Omnistay\Local\LocalProvider;
use Omnistay\Local\Pricing;
use Omnistay\Local\Rules;
use Omnistay\Model\Location;
use Omnistay\Model\Money;
use Omnistay\Model\Property;
use Omnistay\Model\PropertyKind;
use Omnistay\Model\PropertyRef;
use Omnistay\Model\StayQuery;
use Omnistay\Registry;
use PHPUnit\Framework\TestCase;

/** The site's own listings: no third party's limit, every right - and a site's own limit held through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsTheSitesOwnData(): void
    {
        $policy = (new Registry([self::factory()], ['homes' => ['factory' => 'local']]))->policy('homes');

        self::assertSame('local', $policy->provider);
        self::assertSame([], $policy->rateLimits, 'no limit, and known to be none');
        self::assertSame([Permission::Allowed, Permission::Allowed, Permission::Allowed, Permission::Allowed], [$policy->rights->commercial, $policy->rights->caching->permission, $policy->rights->storage, $policy->rights->redistribution]);
        self::assertFalse($policy->rights->attribution->required);
        self::assertFalse($policy->requirements->key);
        self::assertNull($policy->provenance->verifiedAt, 'no third party\'s terms to read');
        self::assertFileExists(LocalProvider::POLICY);
    }

    public function testASitesOwnLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $registry = new Registry([self::factory()], ['homes' => ['factory' => 'local', 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['search']]], 'rights' => ['redistribution' => 'forbidden']]]]);

        self::assertCount(1, $registry->search('homes')->search(new StayQuery(cityCode: 'NCE'))->items);
        self::assertSame(Permission::Forbidden, $registry->policy('homes')->rights->redistribution, 'a site may tighten its own rights');
        try {
            $registry->search('homes')->search(new StayQuery(cityCode: 'NCE'));
            self::fail('The second search of the minute went through.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('1/minute/application/search', $e->limit?->id());
        }
        $results = $registry->searchAll(new StayQuery(cityCode: 'NCE'));
        self::assertSame([], $results->items);
        self::assertArrayHasKey('homes', $results->failures, 'searchAll names the provider whose limit is reached');
    }

    private static function factory(): LocalFactory
    {
        return new LocalFactory(new InMemoryStore([new Listing(
            new Property(new PropertyRef('homes', 'L-2'), 'Studio du Port', PropertyKind::Apartment, new Location(43.70, 7.28, city: 'Nice', cityCode: 'NCE')),
            new Pricing(Money::of(8000, 'EUR')),
            new Rules(maxGuests: 2),
        )]), static fn () => new \DateTimeImmutable('2026-11-09T10:00:00Z'));
    }
}
