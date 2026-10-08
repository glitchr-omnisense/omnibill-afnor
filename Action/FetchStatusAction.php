<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Request\FetchStatus;
use Omnibill\Request\Request;

/**
 * GET /v1/flows/{flowId}?docType=Metadata: the platform's acknowledgement.
 * The recipient's statuses arrive as lifecycle flows: receive().
 */
final class FetchStatusAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FetchStatus;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchStatus);
        $fresh = Api::flow($this->api->metadata($request->flow->reference), $request->flow->invoiceNumber);
        $history = $request->flow->history;
        if (null !== $fresh->status && !\in_array($fresh->status, array_map(static fn ($c) => $c->status, $history), true)) {
            $history = [...$history, ...$fresh->history];
        }
        $request->setResult($fresh->with(status: $fresh->status ?? $request->flow->status, history: $history));
    }
}
