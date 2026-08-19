<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\ConsentLog\CollectionFactory as ConsentLogCollectionFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Resolves Query.myPersonalDataExport. Returns the data inline rather than
 * generating a server-side file - the client turns this JSON into a
 * downloadable blob. Also logs a completed export_data request so the
 * download shows up in the customer's own request history and the admin
 * consent/request audit trail.
 */
class PersonalDataExport implements ResolverInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ConsentLogCollectionFactory $consentLogCollectionFactory,
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly DateTime $dateTime
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

        $customer = $this->customerRepository->getById($customerId);

        $addresses = [];
        foreach ($customer->getAddresses() ?? [] as $address) {
            $region = $address->getRegion();
            $addresses[] = [
                'firstname' => $address->getFirstname(),
                'lastname' => $address->getLastname(),
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
                'region' => $region ? $region->getRegion() : null,
                'postcode' => $address->getPostcode(),
                'country_code' => $address->getCountryId(),
                'telephone' => $address->getTelephone(),
            ];
        }

        $orders = [];
        $criteria = $this->searchCriteriaBuilder->addFilter('customer_id', $customerId)->create();
        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            $orders[] = [
                'increment_id' => $order->getIncrementId(),
                'created_at' => $order->getCreatedAt(),
                'status' => $order->getStatus(),
                'grand_total' => (float) $order->getGrandTotal(),
            ];
        }

        $consentHistory = [];
        $logCollection = $this->consentLogCollectionFactory->create();
        $logCollection->addFieldToFilter('customer_id', $customerId)->setOrder('created_at', 'DESC');
        foreach ($logCollection as $log) {
            $consentHistory[] = [
                'context' => $log->getData('context'),
                'necessary' => (bool) $log->getData('necessary'),
                'analytics' => (bool) $log->getData('analytics'),
                'marketing' => (bool) $log->getData('marketing'),
                'preferences' => (bool) $log->getData('preferences'),
                'created_at' => $log->getData('created_at'),
            ];
        }

        $now = $this->dateTime->gmtDate();

        $request = $this->requestFactory->create();
        $request->addData([
            'customer_id' => $customerId,
            'type' => DsrRequest::TYPE_EXPORT_DATA,
            'status' => DsrRequest::STATUS_COMPLETED,
            'resolved_at' => $now,
        ]);
        $this->requestResource->save($request);

        return [
            'generated_at' => $now,
            'profile' => [
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'email' => $customer->getEmail(),
                'date_of_birth' => $customer->getDob(),
            ],
            'addresses' => $addresses,
            'orders' => $orders,
            'consent_history' => $consentHistory,
        ];
    }
}
