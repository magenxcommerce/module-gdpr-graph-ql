<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\ResourceModel\DsrRequest\CollectionFactory as RequestCollectionFactory;
use Magenx\GdprGraphQl\Model\DsrRequestMapper;
use Magenx\GdprGraphQl\Model\GdprAccess;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves Query.myGdprRequests - the signed-in customer's own request
 * history, newest first.
 */
class MyGdprRequests implements ResolverInterface
{
    /**
     * A data-subject request history is a handful of rows in normal use; this
     * only bounds the response if something has gone wrong upstream.
     */
    private const MAX_REQUESTS = 200;

    public function __construct(
        private readonly RequestCollectionFactory $requestCollectionFactory,
        private readonly DsrRequestMapper $mapper,
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
        $customerId = $this->access->requireCustomerId($context);

        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->setOrder('requested_at', 'DESC')
            ->setPageSize(self::MAX_REQUESTS);

        $result = [];
        foreach ($collection as $request) {
            $result[] = $this->mapper->toGraphQl($request);
        }

        return $result;
    }
}
