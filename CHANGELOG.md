# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnistay` `^1.0` with no stability flag, so `composer require omnistay/local` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- The site's listings as a provider of all six contracts: the store contract, pricing (weekend,
  seasons, a night's own price, extra guests, discounts, cleaning, service fee, tourist tax),
  rules, calendar, bookings held atomically, pending holds that expire, cancellation terms,
  InMemoryStore.
