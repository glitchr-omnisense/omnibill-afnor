<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\Syntax;
use Omnibill\Request\Request;
use Omnibill\Request\SetStatus;

/**
 * A status declared: its lifecycle message (CDAR), produced by the
 * application, submitted as a flow of its own - since omnibill 1.3 with the
 * request's trackingId in its flowInfo, so that findFlow() finds it again
 * after an OutcomeUnknownException. The file is sent as given, unread.
 */
final class SetStatusAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof SetStatus;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SetStatus);
        $change = $request->change;
        $file = $change->file ?? throw new InvalidConfigException('An approved platform carries a status as a lifecycle message: give the StatusChange its CDAR file - the family does not write one.');
        $name = $change->filename ?? \sprintf('CDV-%s-%s.xml', $change->status->code() ?? $change->status->value, preg_replace('~[^A-Za-z0-9_-]+~', '-', (string) $change->invoiceNumber));
        $info = $this->api->submit($file, $name, Syntax::CDAR, $request->trackingId, null, null);
        $flow = Api::flow(['flowSyntax' => 'CDAR', 'flowDirection' => 'Out', 'acknowledgement' => ['status' => 'Pending']] + $info + array_filter(['trackingId' => $request->trackingId]), $change->invoiceNumber);
        $request->setResult($flow->with(status: $change->status, history: [...($request->flow->history ?? []), $change]));
    }
}
