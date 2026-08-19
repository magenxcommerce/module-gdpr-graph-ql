<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\ConsentLogFactory;
use Magenx\Gdpr\Model\ResourceModel\ConsentLog as ConsentLogResource;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Resolves Mutation.submitCookieConsent. Guest-usable by design - most
 * consent decisions happen before anyone signs in - so the customer id is
 * attached only when the request actually carries one.
 */
class SubmitCookieConsent implements ResolverInterface
{
    public function __construct(
        private readonly ConsentLogFactory $consentLogFactory,
        private readonly ConsentLogResource $consentLogResource,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $input = $args['input'] ?? [];
        if (!array_key_exists('analytics', $input) || !array_key_exists('marketing', $input) || !array_key_exists('preferences', $input)) {
            throw new GraphQlInputException(__('analytics, marketing and preferences are required.'));
        }

        $isCustomer = (bool) $context->getExtensionAttributes()->getIsCustomer();
        $customerId = $isCustomer ? (int) $context->getUserId() : null;

        $log = $this->consentLogFactory->create();
        $log->addData([
            'customer_id' => $customerId,
            'ip_address' => $this->remoteAddress->getRemoteAddress(),
            'context' => 'cookie_banner',
            'necessary' => 1,
            'analytics' => (bool) $input['analytics'] ? 1 : 0,
            'marketing' => (bool) $input['marketing'] ? 1 : 0,
            'preferences' => (bool) $input['preferences'] ? 1 : 0,
        ]);
        $this->consentLogResource->save($log);

        return true;
    }
}
