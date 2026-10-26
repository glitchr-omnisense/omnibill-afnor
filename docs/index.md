---
title: omnibill/afnor
order: 1
---

# omnibill/afnor

## Installation

```sh
composer require omnibill/afnor
```

PHP 8.2 or later, `ext-dom`, `omnisense/omnibill` and `symfony/http-client` (give the factory your
application's client, a `MockHttpClient` in a test). An account on an approved platform that
implements XP Z12-013: its addresses, an OAuth 2.0 client.

## The sources, and what they leave out

- **XP Z12-013's OpenAPI, version 1.3.0** - annex A, "AFNOR Flow Service", and annex B, "AFNOR
  Directory Service" - as the FNFE-MPE publishes them with the standard
  ([fnfe-mpe.org/ressources](https://fnfe-mpe.org/ressources/), "XP Z12-013 (30/06/2026) ... avec
  les swaggers", read on 2026-10-08): the routes, their parameters and bodies, the schemas, the
  OAuth 2.0 client credentials, the webhooks' signature.
- **The DGFiP's external specifications v3.2** (2026-04-30,
  [impots.gouv.fr/factures-norme-afnor](https://www.impots.gouv.fr/factures-norme-afnor)): the
  lifecycle statuses and their codes (general file), where a CDAR message carries the invoice's
  number and its status (Annex 2, "Format sémantique FE CDV - Flux 6").
- **Not read**: the text of XP Z12-013 itself, and XP Z12-012 (formats, profiles, lifecycle
  messages) - AFNOR's documents, read on AFNOR's site under its terms. What only they say is not
  here: which routes a platform must implement and which it may leave out, the rules that decide a
  flow's processing rule, the CDAR message's full content (this package reads it, it does not write
  one), and whether the webhook's HMAC is computed over the fingerprint's bytes or its
  hexadecimal form - both are accepted (below).

## Options

| Option | Default | |
|---|---|---|
| `flow_url` | required | the Flow Service's root, before `/v1`: `https://api.flow.<domain>/flow-service`, as the platform gives it |
| `directory_url` | | the Directory Service's root; without it, `lookup()` is not supported |
| `token_url` | required | the OAuth 2.0 token endpoint (client credentials) |
| `client_id`, `client_secret` | required | |
| `scope` | | when the platform asks one |
| `organization_id` | | the `Organization-Id` header: the company acted for, under a delegation |
| `webhook_key` | | the signing key the platform gave when the webhook was created (base64); without it, `notify()` is not supported |
| `webhook_tolerance` | 300 | seconds a callback may be old; 0: any age |
| `profile`, `rule` | | the `flowProfile` (Basic, CIUS, Extended-CTC-FR) and `processingRule` (B2B, B2BInt, B2C, B2G, B2GInt, OutOfScope...) of invoices that give none |

Each call carries `Authorization: Bearer <token>` (kept until a minute before it lapses, renewed
once on a 401), a `Request-Id` (a UUID, for the platform's logs) and `Organization-Id` when set.

## Operations

| | Route | |
|---|---|---|
| `submit()` | `POST /v1/flows`, multipart: `flowInfo` (JSON: trackingId, name, processingRule, flowSyntax, flowProfile, sha256) and `file` | 202: the flow, `PENDING` |
| `fetchStatus()` | `GET /v1/flows/{flowId}?docType=Metadata` | the acknowledgement: `Pending`, `Ok` (`ACCEPTED`; an invoice sent: `DEPOSITED`, 200), `Error` (`REJECTED`; an invoice sent: `REJECTED`, 213), with its details as errors |
| `receive()` | `POST /v1/flows/search` (`where.flowDirection: [In]`, `limit`, `cursor`), then `GET /v1/flows/{flowId}?docType=Original` for each | an invoice (Factur-X, UBL, CII) as an `Invoice`; a lifecycle message (CDAR) read as a `StatusChange`; the `nextCursor` |
| `setStatus()` | `POST /v1/flows`, `flowSyntax: CDAR` | the application's CDAR file (`StatusChange::$file`): an `InvalidConfigException` without one |
| `report()` | `POST /v1/flows`, `flowSyntax: FRR` | |
| `lookup()` | `POST /v1/directory-line/search`, `filters.siren` or `filters.siret` (`op: strict`), `include: [siren, siret, routingCode]` | `list<Addressing>`; 204: none |
| `notify()` | a webhook's call | its `Afnor-Signature` checked; the `Flow` it carries |

Reading the acknowledgement as the invoice's first status (Ok: Déposée, Error: Rejetée) is this
package's reading of the two definitions - "la plateforme agréée d'émission atteste que la facture
est contrôlée et conforme", "un des contrôles ... a détecté une anomalie" -, not a rule of the
standard's.

A lifecycle message is read where Annex 2 puts things:
`AcknowledgementDocument/ReferenceReferencedDocument/ProcessConditionCode` (the status, 200 to
213, `fr:212` taken too), `.../IssuerAssignedID` (the invoice's number), a `ReasonInformation`,
`ExchangedDocument/IssueDateTime/DateTimeString` (format 204). A message without a status of the
reform is not read as one. XML entities are not expanded, nothing is fetched.

## Webhooks

Created on the platform (`POST /v1/webhooks`, not done by this package): it answers an id and a
**signing key** - the `webhook_key` option. Each callback carries the `Flow` as JSON, and:

- `Afnor-Signature-Timestamp`: epoch seconds;
- `Afnor-Signature`: base64 of an HMAC-SHA-256, under the signing key, of the SHA-256 fingerprint
  of `<body>@<timestamp>`.

The OpenAPI does not say whether the HMAC covers the fingerprint's 32 bytes or its 64 hexadecimal
characters: both are accepted, each still needing the key. A callback older than
`webhook_tolerance` is refused. Answer 200 quickly, and `receive()` out of the request.

## Verified, and not

| | |
|---|---|
| Against an approved platform | **not verified in real: no account.** Every answer in `Tests/Fixtures` is written from the OpenAPI's schemas, the lifecycle message from Annex 2 |
| The multipart body, the token, the headers, the cursor, the signature | by the tests, against the OpenAPI |
| Which routes a given platform implements | unknown until tried: a route it lacks answers an error, thrown as a `ProviderException` |
