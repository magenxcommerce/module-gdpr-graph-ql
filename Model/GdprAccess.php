<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model;

use Magenx\Gdpr\Model\Config;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * The two checks every resolver in this module has to make, in one place: is
 * the module switched on for the store being queried, and is there actually a
 * signed-in customer behind this request.
 *
 * The store check exists because magenx_gdpr/general/enabled is documented in
 * the admin as a master switch over "the cookie registry query and
 * consent/request mutations" - before this it gated only the two anonymization
 * crons, so turning it off left every GraphQL entry point running.
 */
class GdprAccess
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /** True when the module is enabled for the store this query is running against. */
    public function isEnabled(ContextInterface $context): bool
    {
        return $this->config->isEnabled($this->storeId($context));
    }

    /**
     * @throws GraphQlInputException when the module is switched off for this store.
     */
    public function assertEnabled(ContextInterface $context): void
    {
        if (!$this->isEnabled($context)) {
            throw new GraphQlInputException(__('GDPR requests are not available for this store.'));
        }
    }

    /**
     * @throws GraphQlAuthorizationException when the request carries no customer.
     */
    public function requireCustomerId(ContextInterface $context): int
    {
        if (!$context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        return (int) $context->getUserId();
    }

    private function storeId(ContextInterface $context): ?int
    {
        $store = $context->getExtensionAttributes()->getStore();

        return $store ? (int) $store->getId() : null;
    }
}
