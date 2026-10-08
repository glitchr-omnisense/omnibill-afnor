<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Request\Lookup;
use Omnibill\Request\Request;

/** POST /v1/directory-line/search: where a SIREN or a SIRET receives its invoices. */
final class LookupAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Lookup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Lookup);
        $identifier = preg_replace('~\s+~', '', $request->identifier);
        if (!preg_match('~^(\d{9}|\d{14})$~', (string) $identifier)) {
            throw new InvalidConfigException(\sprintf('The directory is asked by SIREN (9 digits) or SIRET (14): "%s".', $request->identifier));
        }
        $request->setResult($this->api->lookup((string) $identifier));
    }
}
