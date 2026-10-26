# omnibill/afnor

An approved platform (plateforme agréée) for [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill),
through the standard API of XP Z12-013 - one package for every platform that implements it, told
apart by their addresses and credentials. It submits invoices, follows their acknowledgement,
receives suppliers' invoices and lifecycle statuses, declares statuses, reads the national directory,
transmits e-reporting files and checks webhooks. The gateway calls, on the platform's addresses (no
default: each platform gives its own; the platform's test environment is another set of addresses):

| Operation | Service | Route |
|---|---|---|
| token | OAuth 2.0 | `POST <token_url>`, `grant_type=client_credentials` |
| submit, setStatus, report | Flow Service | `POST <flow_url>/v1/flows` (multipart) |
| fetchStatus | Flow Service | `GET <flow_url>/v1/flows/{flowId}?docType=Metadata` |
| receive | Flow Service | `POST <flow_url>/v1/flows/search`, then `GET <flow_url>/v1/flows/{flowId}?docType=Original` |
| lookup | Directory Service | `POST <directory_url>/v1/directory-line/search` |
| notify | - | the platform's call to the application's webhook |

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

## Credentials

From the platform the company signed with (the list of approved platforms:
[impots.gouv.fr](https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees)):

- the Flow Service's and the Directory Service's root addresses (`flow_url`, `directory_url`);
- an OAuth 2.0 client for the client credentials grant: its token endpoint, id and secret, and a
  scope when the platform asks one;
- under a delegation (an accountant acting for its clients), the company's `Organization-Id`;
- for webhooks, the signing key the platform answers when the webhook is created.

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

## Capabilities

| Request | Supported |
|---|---|
| `Submit` | yes: Factur-X, UBL, CII (100 MB, said not enforced) |
| `FetchStatus` | yes: the acknowledgement |
| `Receive` | yes |
| `SetStatus` | yes, with the application's CDAR file |
| `Lookup` | only with `directory_url` |
| `Report` | yes: FRR |
| `Notify` | only with `webhook_key` |

`capabilities()`: Factur-X, UBL, CII, CDAR, FRR; approved platform; public sector (an approved
platform reaches public entities too); 100 MB.

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibill\Afnor\AfnorGatewayFactory;
use Omnibill\Model\Invoice;
use Omnibill\Model\Party;
use Omnibill\Model\Syntax;
use Symfony\Component\HttpClient\HttpClient;

$platform = (new AfnorGatewayFactory(HttpClient::create()))->create([
    'flow_url' => getenv('PA_FLOW_URL'),
    'directory_url' => getenv('PA_DIRECTORY_URL'),
    'token_url' => getenv('PA_TOKEN_URL'),
    'client_id' => getenv('PA_CLIENT_ID'),
    'client_secret' => getenv('PA_CLIENT_SECRET'),
    'rule' => 'B2B',
]);

$invoice = new Invoice(file_get_contents('F-2026-042.pdf'), 'F-2026-042.pdf', Syntax::FACTURX, 'F-2026-042', buyer: new Party('Maison Érable', '702042755'), profile: 'Basic');
$flow = $platform->submit($invoice, 'order-1234');                   // PENDING: keep $flow->reference
$flow = $platform->fetchStatus($flow);                               // ACCEPTED, DEPOSITED (200) - or REJECTED with errors
$inbox = $platform->receive($cursor);                                // suppliers' invoices, statuses of the company's own
$lines = $platform->lookup('702042755');                             // where the buyer receives
```

Run on 2026-10-26 on a `MockHttpClient` serving this package's fixtures (the harness's
`recorded.php`), PHP 8.4, no network - the requests (`>`) and what each call answered:

```
submit
  > POST https://auth.pa.example/auth-service/acme/token
  > POST https://api.flow.pa.example/flow-service/v1/flows
  8f1d2c3b-4a5e-4f60-9a7b-1c2d3e4f5a6b pending invoice order-42
fetchStatus
  > GET https://api.flow.pa.example/flow-service/v1/flows/8f1d2c3b-4a5e-4f60-9a7b-1c2d3e4f5a6b?docType=Metadata
  accepted deposited, history: deposited (200)
receive
  > POST https://api.flow.pa.example/flow-service/v1/flows/search
  > GET https://api.flow.pa.example/flow-service/v1/flows/c1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b?docType=Original
  > GET https://api.flow.pa.example/flow-service/v1/flows/d2b3c4d5-e6f7-4a80-9b0c-1d2e3f4a5b6c?docType=Original
  invoice in FA-77.xml -
  lifecycle in - F-2026-042 paid 212
  cursor 'eyJjcmVhdGVkX2F0IjoiMjAyNi0xMC0wOFQwOTowMDowMFoiLCJpZCI6MTIzNH0=', more true
lookup
  > POST https://api.directory.pa.example/directory-service/v1/directory-line/search
  702042755 - 702042755 WK Enabled
  702042755 70204275500240 702042755_70204275500240_ACHATS WK Upcoming
```

(the recorded answer's `trackingId` is `order-42`.) The rest - report, setStatus, a signed webhook:
[the manual's usage pages](https://github.com/glitchr-omnisense/omnibill/blob/1.x/docs/usage-submitting.md). In Symfony:

```yaml
omnibill:
    gateways:
        platform:
            factory: afnor
            options:
                flow_url: '%env(PA_FLOW_URL)%'
                directory_url: '%env(PA_DIRECTORY_URL)%'
                token_url: '%env(PA_TOKEN_URL)%'
                client_id: '%env(PA_CLIENT_ID)%'
                client_secret: '%env(PA_CLIENT_SECRET)%'
                webhook_key: '%env(default::PA_WEBHOOK_KEY)%'
```

## Errors

| Answer | Exception |
|---|---|
| the token endpoint's 400, 401, 403, or no `access_token` | `InvalidKeyException` |
| 401 | the token forgotten and asked again once; a second 401 `InvalidKeyException` |
| 403 | `InvalidKeyException` (`The platform refused the request (HTTP 403): ...`) |
| other 4xx | `ProviderException` (`HTTP 422: <errorCode> <errorMessage> - <details>`, `providerCode` the `errorCode`) |
| 5xx, 429, no answer, a timeout (10 s) | `UnreachableException`: nothing transmitted |
| a body that is not JSON where JSON is expected | `ProviderException` |
| a flow without a `flowId` | `ProviderException` |
| a lookup identifier that is not 9 or 14 digits; a status without its CDAR file | `InvalidConfigException`, before any call |
| a webhook that does not hold | `InvalidNotificationException` |

## Verified, and not

| | |
|---|---|
| Against an approved platform | **not verified in real: no account.** Every answer in `Tests/Fixtures` is written from the OpenAPI's schemas, the lifecycle message from Annex 2 |
| The multipart body, the token, the headers, the cursor, the signature | by the tests, against the OpenAPI |
| Which routes a given platform implements | unknown until tried: a route it lacks answers an error, thrown as a `ProviderException` |

The package's tests (`Tests/AfnorGatewayTest.php`, 9 tests) pass on 2026-10-26 (PHP 8.4).

## Responsibility

This package is an independent, unofficial client of the XP Z12-013 API: it is not affiliated with,
endorsed by or sponsored by AFNOR, the FNFE-MPE or any approved platform. Whoever runs it
complies with the platform's contract and terms and with the reform's obligations; see the README's
Responsibility section.
