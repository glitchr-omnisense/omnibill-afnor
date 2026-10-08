<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\Syntax;
use Omnibill\Request\Request;
use Omnibill\Request\SetStatus;

/**
 * A status declared: its lifecycle message (CDAR), produced by the
 * application, submitted as a flow of its own.
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
        $info = $this->api->submit($file, $name, Syntax::CDAR, null, null, null);
        $flow = Api::flow(['flowSyntax' => 'CDAR', 'flowDirection' => 'Out', 'acknowledgement' => ['status' => 'Pending']] + $info, $change->invoiceNumber);
        $request->setResult($flow->with(status: $change->status, history: [...($request->flow?->history ?? []), $change]));
    }
}
