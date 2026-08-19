<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\ResourceModel\Cookie\CollectionFactory as CookieCollectionFactory;
use Magenx\Gdpr\Model\ResourceModel\CookieGroup\CollectionFactory as CookieGroupCollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves Query.gdprCookieGroups. Public, store-wide data - same answer for
 * every visitor - so it stays cacheable (no @cache(cacheable:false) in the
 * schema, unlike the customer-private resolvers in this module).
 */
class CookieGroups implements ResolverInterface
{
    public function __construct(
        private readonly CookieGroupCollectionFactory $groupCollectionFactory,
        private readonly CookieCollectionFactory $cookieCollectionFactory
    ) {
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $groups = $this->groupCollectionFactory->create();
        $groups->addFieldToFilter('is_active', 1)->setOrder('sort_order', 'ASC');

        $cookies = $this->cookieCollectionFactory->create();
        $cookies->addFieldToFilter('is_active', 1)->setOrder('sort_order', 'ASC');

        $cookiesByGroupId = [];
        foreach ($cookies as $cookie) {
            $cookiesByGroupId[(int) $cookie->getData('group_id')][] = [
                'name' => $cookie->getData('name'),
                'purpose' => $cookie->getData('purpose'),
                'duration_label' => $cookie->getData('duration_label'),
                'source' => $cookie->getData('source'),
            ];
        }

        $result = [];
        foreach ($groups as $group) {
            $result[] = [
                'code' => $group->getData('code'),
                'label' => $group->getData('label'),
                'description' => $group->getData('description'),
                'is_required' => (bool) $group->getData('is_required'),
                'cookies' => $cookiesByGroupId[(int) $group->getId()] ?? [],
            ];
        }

        return $result;
    }
}
