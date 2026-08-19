<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\Anonymizer;
use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\GdprGraphQl\Model\DsrRequestMapper;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Resolves Mutation.requestGdprAction.
 *
 * anonymize_data runs immediately through Anonymizer (self-service, like the
 * export query) and is recorded already completed. erase_data only ever
 * creates a pending row here - Anonymizer only runs for it once an admin
 * approves it from the Data Requests grid, since it's the most destructive
 * action a customer can trigger on their own account.
 */
class RequestGdprAction implements ResolverInterface
{
    public function __construct(
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly Anonymizer $anonymizer,
        private readonly DsrRequestMapper $mapper,
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

        $type = strtolower((string) ($args['input']['type'] ?? ''));
        $note = $args['input']['note'] ?? null;

        if ($type === DsrRequest::TYPE_EXPORT_DATA) {
            throw new GraphQlInputException(__('Use myPersonalDataExport to download your data.'));
        }
        if (!in_array($type, [DsrRequest::TYPE_ANONYMIZE_DATA, DsrRequest::TYPE_ERASE_DATA], true)) {
            throw new GraphQlInputException(__('Unknown request type.'));
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
            'admin_note' => $note,
        ]);

        if ($type === DsrRequest::TYPE_ANONYMIZE_DATA) {
            $this->anonymizer->anonymizeCustomer($customerId);
            $request->setData('status', DsrRequest::STATUS_COMPLETED);
            $request->setData('resolved_at', $this->dateTime->gmtDate());
        }

        $this->requestResource->save($request);

        return $this->mapper->toGraphQl($request);
    }
}
