# omnibill/afnor

**An approved platform** (plateforme agréée) for [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill),
through the standard API of **XP Z12-013** - one package for every platform that implements it,
told apart by its addresses and credentials: its Flow Service (invoices, lifecycle messages and
e-reporting files as flows, their acknowledgement, what comes in, webhooks) and its Directory
Service (where a company receives).

```php
use Omnibill\Afnor\AfnorGatewayFactory;

$platform = (new AfnorGatewayFactory($httpClient))->create([
    'flow_url' => 'https://api.flow.example-pa.fr/flow-service',
    'directory_url' => 'https://api.directory.example-pa.fr/directory-service',
    'token_url' => 'https://auth.example-pa.fr/auth-service/acme/token',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
]);

$flow = $platform->submit($invoice);              // POST /v1/flows: PENDING
$platform->fetchStatus($flow)->status;            // Ok: DEPOSITED (200); Error: REJECTED (213), ->errors
$inbox = $platform->receive($cursor);             // suppliers' invoices; statuses (CDAR) read: PAID, REFUSED...
$platform->lookup('702042755');                   // the directory's lines for a SIREN or a SIRET
```

Written from the standard's **OpenAPI 1.3.0** (Flow Service and Directory Service, annexes A and B,
published with the standard by the FNFE-MPE on 2026-06-30) and, for lifecycle messages, from the
DGFiP's external specifications (Annex 2, Flux 6). **Not verified against a platform: no account.**

[Documentation](docs/index.md): the sources and what is missing from them, the options, each
operation and its route, the webhooks' signature, what was verified.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by AFNOR, the FNFE-MPE, or any approved platform (plateforme agréée). It reaches the
approved platform it is pointed to on behalf of whoever runs it, and it does not redistribute their
data beyond the short excerpts recorded as test fixtures. Its MIT licence covers this package's code
only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms of that platform
(the DGFiP lists the approved platforms:
[impots.gouv.fr](https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
