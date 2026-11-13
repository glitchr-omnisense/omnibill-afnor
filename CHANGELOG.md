# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnibill AFNOR: an approved platform (plateforme agréée) through the standard API of XP Z12-013 - the Flow Service (submit an invoice, a lifecycle status or an e-reporting file, follow its acknowledgement, receive suppliers' invoices and statuses, webhooks with their signature checked) and the Directory Service (where a SIREN or a SIRET receives); any platform that implements the standard, by its address.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnibill: ^1.0`: stable versions, so `composer require omnibill/afnor` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
