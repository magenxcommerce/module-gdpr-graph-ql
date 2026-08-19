<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\Anonymizer;
use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest\CollectionFactory as RequestCollectionFactory;
use Magenx\GdprGraphQl\Model\DsrRequestMapper;
use Magenx\GdprGraphQl\Model\GdprAccess;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Resolves Mutation.requestGdprAction.
 *
 * anonymize_data runs immediately through Anonymizer (self-service, like the
 * export query). erase_data only ever creates a pending row here - Anonymizer
 * only runs for it once an admin approves it from the Data Requests grid, since
 * it's the most destructive action a customer can trigger on their own account.
 *
 * The request row is always persisted as pending *before* the anonymization
 * runs, and only moved to completed once it has. A failure in between therefore
 * leaves a visible pending request rather than silently destroying data with no
 * record, and the duplicate check below stops the customer from re-submitting
 * over the top of it.
 */
class RequestGdprAction implements ResolverInterface
{
    /** Ceiling on the free-text note, so a TEXT column cannot be used as storage. */
    private const MAX_NOTE_LENGTH = 1000;

    public function __construct(
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly RequestCollectionFactory $requestCollectionFactory,
        private readonly Anonymizer $anonymizer,
        private readonly DsrRequestMapper $mapper,
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

        $type = strtolower((string) ($args['input']['type'] ?? ''));
        $note = $this->normalizeNote($args['input']['note'] ?? null);

        if ($type === DsrRequest::TYPE_EXPORT_DATA) {
            throw new GraphQlInputException(__('Use myPersonalDataExport to download your data.'));
        }
        if (!in_array($type, [DsrRequest::TYPE_ANONYMIZE_DATA, DsrRequest::TYPE_ERASE_DATA], true)) {
            throw new GraphQlInputException(__('Unknown request type.'));
        }

        if ($this->hasPendingRequest($customerId, $type)) {
            throw new GraphQlInputException(
                __('You already have a request of this kind waiting to be processed.')
            );
        }

        if ($this->anonymizer->hasOpenOrders($customerId)) {
            throw new GraphQlInputException(
                __('You have an order in progress. Please wait until it is complete before requesting this.')
            );
        }

        $request = $this->requestFactory->create();
        $request->addData([
            'customer_id' => $customerId,
            'type' => $type,
            'status' => DsrRequest::STATUS_PENDING,
            'customer_note' => $note,
        ]);
        $this->requestResource->save($request);

        if ($type === DsrRequest::TYPE_ANONYMIZE_DATA) {
            $this->anonymizer->anonymizeCustomer($customerId);
            $request->setData('status', DsrRequest::STATUS_COMPLETED);
            $request->setData('resolved_at', $this->dateTime->gmtDate());
            $this->requestResource->save($request);
        }

        return $this->mapper->toGraphQl($request);
    }

    /** Whether this customer already has an unresolved request of the same type. */
    private function hasPendingRequest(int $customerId, string $type): bool
    {
        $collection = $this->requestCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('type', $type)
            ->addFieldToFilter('status', DsrRequest::STATUS_PENDING)
            ->setPageSize(1);

        return (bool) $collection->getFirstItem()->getId();
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);
        if ($note === '') {
            return null;
        }

        return mb_substr($note, 0, self::MAX_NOTE_LENGTH);
    }
}
