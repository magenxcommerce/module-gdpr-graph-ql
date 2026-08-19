<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\GdprGraphQl\Model\DsrRequestMapper;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest\CollectionFactory as RequestCollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class MyGdprRequests implements ResolverInterface
{
    public function __construct(
        private readonly RequestCollectionFactory $requestCollectionFactory,
        private readonly DsrRequestMapper $mapper
    ) {
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }
        $customerId = (int) $context->getUserId();

        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)->setOrder('requested_at', 'DESC');

        $result = [];
        foreach ($collection as $request) {
            $result[] = $this->mapper->toGraphQl($request);
        }

        return $result;
    }
}
