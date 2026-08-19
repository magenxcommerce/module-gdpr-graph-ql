<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\ConsentLog\CollectionFactory as ConsentLogCollectionFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest\CollectionFactory as RequestCollectionFactory;
use Magenx\GdprGraphQl\Model\GdprAccess;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
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
 *
 * This is a query that writes, which GraphQL normally reserves for mutations.
 * That is deliberate and load-bearing: the storefront is already wired to it as
 * a query (see MY_PERSONAL_DATA_EXPORT in the engine package), and Article 15
 * asks that access requests be recorded. It is marked @cache(cacheable: false)
 * so nothing prefetches or replays it, and the log write is deduplicated below
 * so repeated clicks do not each add a row.
 *
 * Covers the personal data this module and Magento's core customer/sales tables
 * hold: profile, addresses, order summary and cookie-consent history. It does
 * not attempt to reach into third-party modules' tables - a store with those
 * installed has to extend this resolver to stay complete.
 */
class PersonalDataExport implements ResolverInterface
{
    /** Orders are paged rather than loaded at once - some customers have thousands. */
    private const ORDER_PAGE_SIZE = 200;

    /** Repeat downloads inside this window reuse the request row already logged. */
    private const LOG_DEDUPE_SECONDS = 300;

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ConsentLogCollectionFactory $consentLogCollectionFactory,
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly RequestCollectionFactory $requestCollectionFactory,
        private readonly DateTime $dateTime,
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

        $now = $this->dateTime->gmtDate();

        $this->logExportRequest($customerId, $now);

        return [
            'generated_at' => $now,
            'profile' => [
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'email' => $customer->getEmail(),
                'date_of_birth' => $customer->getDob(),
            ],
            'addresses' => $addresses,
            'orders' => $this->collectOrders($customerId),
            'consent_history' => $this->collectConsentHistory($customerId),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function collectOrders(int $customerId): array
    {
        $orders = [];
        $page = 1;

        do {
            $criteria = $this->searchCriteriaBuilder->addFilter('customer_id', $customerId)->create();
            $criteria->setPageSize(self::ORDER_PAGE_SIZE);
            $criteria->setCurrentPage($page);

            $items = $this->orderRepository->getList($criteria)->getItems();
            foreach ($items as $order) {
                $orders[] = [
                    'increment_id' => $order->getIncrementId(),
                    'created_at' => $order->getCreatedAt(),
                    'status' => $order->getStatus(),
                    'grand_total' => (float) $order->getGrandTotal(),
                ];
            }
            $page++;
        } while (count($items) === self::ORDER_PAGE_SIZE);

        return $orders;
    }

    /** @return array<int, array<string, mixed>> */
    private function collectConsentHistory(int $customerId): array
    {
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

        return $consentHistory;
    }

    /**
     * Records the access request, unless one was already recorded moments ago.
     * A customer clicking Download twice is one access request, not two, and
     * without this the request history fills with near-identical rows.
     */
    private function logExportRequest(int $customerId, string $now): void
    {
        if ($this->hasRecentExport($customerId, $now)) {
            return;
        }

        $request = $this->requestFactory->create();
        $request->addData([
            'customer_id' => $customerId,
            'type' => DsrRequest::TYPE_EXPORT_DATA,
            'status' => DsrRequest::STATUS_COMPLETED,
            'resolved_at' => $now,
        ]);
        $this->requestResource->save($request);
    }

    private function hasRecentExport(int $customerId, string $now): bool
    {
        // Both $now and requested_at are UTC; parse explicitly in UTC so the
        // window does not shift with PHP's ambient timezone.
        $since = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d seconds', self::LOG_DEDUPE_SECONDS))
            ->format('Y-m-d H:i:s');

        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('type', DsrRequest::TYPE_EXPORT_DATA)
            ->addFieldToFilter('requested_at', ['gteq' => $since])
            ->setPageSize(1);

        return (bool) $collection->getFirstItem()->getId();
    }
}
