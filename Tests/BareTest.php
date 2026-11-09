<?php

namespace Omnistay\Local\Tests;

use Omnistay\Local\LocalProvider;
use Omnistay\Testing\BareTestCase;

/** omnistay/local's classes load with omnisense/omnistay and Symfony's HTTP client contracts alone - not even an HTTP client: it calls nothing. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return LocalProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
