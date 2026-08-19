<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\ConsentLogFactory;
use Magenx\Gdpr\Model\ResourceModel\ConsentLog as ConsentLogResource;
use Magenx\Gdpr\Model\ResourceModel\ConsentLog\CollectionFactory as ConsentLogCollectionFactory;
use Magenx\GdprGraphQl\Model\GdprAccess;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Resolves Mutation.submitCookieConsent. Guest-usable by design - most
 * consent decisions happen before anyone signs in - so the customer id is
 * attached only when the request actually carries one.
 *
 * For a signed-in customer, a submission that repeats their current consent
 * state is not written again. The audit trail is the sequence of *changes*, so
 * a duplicate row records nothing new, and without this a mutation that any
 * client may call without authenticating can be looped to grow the log without
 * limit. Guest submissions are never deduplicated: behind a CDN or load
 * balancer every guest shares one remote address (see below), so matching on it
 * would discard real consent decisions from different visitors.
 *
 * On the recorded IP: RemoteAddress reports the connecting peer, which behind a
 * proxy is the proxy. Making it the visitor's address requires configuring
 * Magento's own trusted-proxy arguments for
 * Magento\Framework\HTTP\PhpEnvironment\RemoteAddress with the deployment's
 * real proxy list - see the README. This module deliberately does not set them
 * itself: trusting a forwarding header without knowing which hops are yours
 * lets any client forge the address in its own audit record.
 */
class SubmitCookieConsent implements ResolverInterface
{
    public function __construct(
        private readonly ConsentLogFactory $consentLogFactory,
        private readonly ConsentLogResource $consentLogResource,
        private readonly ConsentLogCollectionFactory $consentLogCollectionFactory,
        private readonly RemoteAddress $remoteAddress,
        private readonly GdprAccess $access
    ) {
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $this->access->assertEnabled($context);

        // No presence check on the three categories: GdprConsentInput declares
        // them Boolean!, so a missing one is rejected by GraphQL before any
        // resolver runs.
        $input = $args['input'];
        $choices = [
            'analytics' => $input['analytics'] ? 1 : 0,
            'marketing' => $input['marketing'] ? 1 : 0,
            'preferences' => $input['preferences'] ? 1 : 0,
        ];

        $isCustomer = (bool) $context->getExtensionAttributes()->getIsCustomer();
        $customerId = $isCustomer ? (int) $context->getUserId() : null;

        if ($customerId !== null && $this->matchesLatestConsent($customerId, $choices)) {
            return true;
        }

        $log = $this->consentLogFactory->create();
        $log->addData($choices + [
            'customer_id' => $customerId,
            'ip_address' => $this->remoteAddress->getRemoteAddress(),
            'context' => 'cookie_banner',
            'necessary' => 1,
        ]);
        $this->consentLogResource->save($log);

        return true;
    }

    /**
     * True when the customer's most recent consent row already records exactly
     * these choices.
     *
     * @param array<string, int> $choices
     */
    private function matchesLatestConsent(int $customerId, array $choices): bool
    {
        $collection = $this->consentLogCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->setOrder('log_id', 'DESC')
            ->setPageSize(1);

        $latest = $collection->getFirstItem();
        if (!$latest->getId()) {
            return false;
        }

        foreach ($choices as $category => $granted) {
            if ((int) $latest->getData($category) !== $granted) {
                return false;
            }
        }

        return true;
    }
}
