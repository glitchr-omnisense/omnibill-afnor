<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Request\Request;
use Omnibill\Request\Submit;

/** POST /v1/flows: the invoice's file and its flowInfo; the platform answers 202, the flow PENDING. */
final class SubmitAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Submit;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Submit);
        $invoice = $request->invoice;
        $info = $this->api->submit($invoice->content, $invoice->filename, $invoice->syntax, $request->trackingId, $invoice->profile, $invoice->rule);
        $request->setResult(Api::flow(['flowSyntax' => $invoice->syntax->value, 'flowDirection' => 'Out', 'acknowledgement' => ['status' => 'Pending']] + $info, $invoice->number));
    }
}
