<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Afnor\Data;
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
 *
 * The kinds the Receive asks for (since omnibill 1.2) go in the search's
 * where.flowType (Api::FLOW_TYPES) when each has flowTypes; a flow of
 * another kind the platform answers anyway is not downloaded.
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
        $flowTypes = [];
        foreach ($request->kinds as $kind) {
            $flowTypes = isset(Api::FLOW_TYPES[$kind->value]) && null !== $flowTypes ? [...$flowTypes, ...Api::FLOW_TYPES[$kind->value]] : null;
        }
        $page = $this->api->search($request->cursor, $request->limit, $flowTypes ?? []);
        $items = [];
        foreach ($page['results'] as $data) {
            $flow = Api::flow($data);
            if (!$request->wants($flow->kind)) {
                continue;
            }
            $file = $this->api->download($flow->reference);
            $name = $flow->name ?? $flow->reference;
            if (FlowKind::LIFECYCLE === $flow->kind) {
                $status = Api::lifecycle($file, $name);
                $items[] = new Received($flow->with(status: $status?->status, history: $status ? [$status] : []), status: $status);
            } elseif (FlowKind::INVOICE === $flow->kind) {
                $items[] = new Received($flow, new Invoice($file, $name, Syntax::tryFrom(Data::cast($data['flowSyntax'] ?? '')) ?? Syntax::CII, rule: Data::of($data)->textOrNull('processingRule'), profile: Data::of($data)->textOrNull('flowProfile')));
            } else {
                $items[] = new Received($flow);
            }
        }
        $request->setResult(new Inbox($items, $page['nextCursor'] ?? $request->cursor, null !== $page['nextCursor']));
    }
}
