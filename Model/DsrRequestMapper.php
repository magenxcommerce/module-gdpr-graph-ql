<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model;

use Magenx\Gdpr\Model\DsrRequest;

/**
 * Shared DB-row -> GraphQL-shape mapping for GdprRequest, used by both the
 * MyGdprRequests and RequestGdprAction resolvers.
 */
class DsrRequestMapper
{
    public function toGraphQl(DsrRequest $request): array
    {
        return [
            'id' => (int) $request->getId(),
            'type' => strtoupper((string) $request->getData('type')),
            'status' => strtoupper((string) $request->getData('status')),
            'admin_note' => $request->getData('admin_note'),
            'requested_at' => $request->getData('requested_at'),
            'resolved_at' => $request->getData('resolved_at'),
        ];
    }
}
