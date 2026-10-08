<?php

namespace Omnibill\Afnor;

use Omnibill\Afnor\Action\FetchStatusAction;
use Omnibill\Afnor\Action\LookupAction;
use Omnibill\Afnor\Action\NotifyAction;
use Omnibill\Afnor\Action\ReceiveAction;
use Omnibill\Afnor\Action\ReportAction;
use Omnibill\Afnor\Action\SetStatusAction;
use Omnibill\Afnor\Action\SubmitAction;
use Omnibill\Config;
use Omnibill\GatewayFactory;
use Omnibill\Http\ClientCredentials;
use Omnibill\Model\Capabilities;
use Omnibill\Model\Syntax;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An approved platform (plateforme agréée) through the standard API of
 * XP Z12-013 - one package for every platform that implements it, told
 * apart by their addresses and credentials.
 *
 *   options:
 *     flow_url: https://api.flow.example-pa.fr/flow-service          # required: the Flow Service's root (before /v1)
 *     directory_url: https://api.directory.example-pa.fr/directory-service   # the Directory Service's root; none: no lookup()
 *     token_url: https://auth.example-pa.fr/auth-service/<customer>/token    # required: OAuth 2.0, client credentials
 *     client_id: '%env(PA_CLIENT_ID)%'                               # required
 *     client_secret: '%env(PA_CLIENT_SECRET)%'                       # required
 *     scope: ~                                                       # when the platform asks one
 *     organization_id: ~                                             # Organization-Id: the company acted for, under a delegation
 *     webhook_key: '%env(default::PA_WEBHOOK_KEY)%'                  # the webhook's signing key (base64): notify() checks Afnor-Signature with it
 *     webhook_tolerance: 300                                         # seconds a callback may be old; 0: any age
 *     profile: ~                                                     # the invoices' flowProfile when the Invoice gives none: Basic, CIUS, Extended-CTC-FR
 *     rule: ~                                                        # their processingRule: B2B, B2BInt, B2C, B2G...
 *
 * The platform decides what it implements beyond the standard's
 * mandatory routes: a route it lacks answers an error, not a refusal.
 */
final class AfnorGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibill.factory_name' => 'afnor',
            'omnibill.factory_title' => 'Approved platform (XP Z12-013)',
            'omnibill.required_options' => ['flow_url', 'token_url', 'client_id', 'client_secret'],
            'directory_url' => null,
            'scope' => null,
            'organization_id' => null,
            'webhook_key' => null,
            'webhook_tolerance' => 300,
            'profile' => null,
            'rule' => null,
            // The Flow Service takes a file of 100 MB at most.
            'omnibill.capabilities' => new Capabilities([Syntax::FACTURX, Syntax::UBL, Syntax::CII, Syntax::CDAR, Syntax::FRR], approved: true, publicSector: true, maxSize: 100 * 1024 * 1024),
            'omnibill.api' => function (Config $c): Api {
                $http = $this->http ?? HttpClient::create();

                return new Api(
                    $http,
                    new ClientCredentials($http, Api::PROVIDER, (string) $c['token_url'], (string) $c['client_id'], (string) $c['client_secret'], $c->get('scope') ?: null),
                    rtrim((string) $c['flow_url'], '/'),
                    $c->get('directory_url') ? rtrim((string) $c['directory_url'], '/') : null,
                    $c->get('organization_id') ?: null,
                    $c->get('webhook_key') ?: null,
                    (int) $c['webhook_tolerance'],
                    $c->get('profile') ?: null,
                    $c->get('rule') ?: null,
                );
            },
            'omnibill.action.submit' => new SubmitAction(),
            'omnibill.action.fetch_status' => new FetchStatusAction(),
            'omnibill.action.receive' => new ReceiveAction(),
            'omnibill.action.set_status' => new SetStatusAction(),
            'omnibill.action.report' => new ReportAction(),
            'omnibill.action.lookup' => static fn (Config $c) => $c->get('directory_url') ? new LookupAction() : null,
            'omnibill.action.notify' => static fn (Config $c) => $c->get('webhook_key') ? new NotifyAction() : null,
        ]);
    }
}
