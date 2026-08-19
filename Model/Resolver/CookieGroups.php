<?php

declare(strict_types=1);

namespace Magenx\GdprGraphQl\Model\Resolver;

use Magenx\Gdpr\Model\ResourceModel\Cookie\CollectionFactory as CookieCollectionFactory;
use Magenx\Gdpr\Model\ResourceModel\CookieGroup\CollectionFactory as CookieGroupCollectionFactory;
use Magenx\GdprGraphQl\Model\GdprAccess;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves Query.gdprCookieGroups. Public, store-wide data - same answer for
 * every visitor - so it stays cacheable (no @cache(cacheable:false) in the
 * schema, unlike the customer-private resolvers in this module).
 *
 * Returns an empty list rather than throwing when the module is switched off:
 * this is a public query on a cacheable page, and a cookie policy page with no
 * declared cookies is a better failure mode than an error in every visitor's
 * response.
 */
class CookieGroups implements ResolverInterface
{
    public function __construct(
        private readonly CookieGroupCollectionFactory $groupCollectionFactory,
        private readonly CookieCollectionFactory $cookieCollectionFactory,
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
        if (!$this->access->isEnabled($context)) {
            return [];
        }

        $groups = $this->groupCollectionFactory->create();
        $groups->addFieldToSelect(['group_id', 'code', 'label', 'description', 'is_required'])
            ->addFieldToFilter('is_active', 1)
            ->setOrder('sort_order', 'ASC');

        $cookies = $this->cookieCollectionFactory->create();
        $cookies->addFieldToSelect(['group_id', 'name', 'purpose', 'duration_label', 'source'])
            ->addFieldToFilter('is_active', 1)
            ->setOrder('sort_order', 'ASC');

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
