<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Model\FlowKind;
use Omnibill\Model\Inbox;
use Omnibill\Model\Invoice;
use Omnibill\Model\Received;
use Omnibill\Model\Syntax;
use Omnibill\Request\Receive;
use Omnibill\Request\Request;

/**
 * POST /v1/flows/search on the flows coming in, from the cursor; each one's
 * file downloaded (docType Original): an invoice from a supplier, or a
 * lifecycle message (CDAR) read for its status.
 */
final class ReceiveAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Receive;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Receive);
        $page = $this->api->search($request->cursor, $request->limit);
        $items = [];
        foreach ($page['results'] as $data) {
            $flow = Api::flow($data);
            $file = $this->api->download($flow->reference);
            $name = $flow->name ?? $flow->reference;
            if (FlowKind::LIFECYCLE === $flow->kind) {
                $status = Api::lifecycle($file, $name);
                $items[] = new Received($flow->with(status: $status?->status, history: $status ? [$status] : []), status: $status);
            } elseif (FlowKind::INVOICE === $flow->kind) {
                $items[] = new Received($flow, new Invoice($file, $name, Syntax::tryFrom((string) ($data['flowSyntax'] ?? '')) ?? Syntax::CII, rule: $data['processingRule'] ?? null, profile: $data['flowProfile'] ?? null));
            } else {
                $items[] = new Received($flow);
            }
        }
        $request->setResult(new Inbox($items, $page['nextCursor'] ?? $request->cursor, null !== $page['nextCursor']));
    }
}
