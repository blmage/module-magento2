<?php

namespace ShoppingFeed\Manager\Model\ResourceModel\Marketplace;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface;
use ShoppingFeed\Manager\Model\ResourceModel\AbstractDb;

class Order extends AbstractDb
{
    const DATA_OBJECT_FIELD_NAMES = [ OrderInterface::ADDITIONAL_FIELDS ];

    protected function _construct()
    {
        $this->_init('sfm_marketplace_order', OrderInterface::ORDER_ID);
    }

    /**
     * @param AbstractModel $object
     * @return $this|void
     * @throws LocalizedException
     */
    protected function _afterSave(AbstractModel $object)
    {
        /** @var OrderInterface $object */
        parent::_afterSave($object);
        $connection = $this->getConnection();
        $objectSalesOrderId = $object->getSalesOrderId();

        $actualSalesOrderId = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [ OrderInterface::SALES_ORDER_ID ])
                ->where('order_id = ?', $object->getId())
        );

        if (empty($actualSalesOrderId)) {
            $actualSalesOrderId = null;
        } else {
            $actualSalesOrderId = (int) $actualSalesOrderId;
        }

        if ($objectSalesOrderId !== $actualSalesOrderId) {
            throw new LocalizedException(__('A marketplace order can only be imported once.'));
        }
    }

    protected function prepareDataForUpdate($object)
    {
        $data = parent::prepareDataForUpdate($object);

        if (isset($data[OrderInterface::SALES_ORDER_ID])) {
            // Prevent importing marketplace orders twice by only updating the `sales_order_id` field when it is empty,
            // or when it has the same value as the one that we are saving.
            // If the check in _afterSave() detects a discrepancy, an exception will be thrown.
            $connection = $this->getConnection();
            $salesOrderId = $data[OrderInterface::SALES_ORDER_ID];

            $data[OrderInterface::SALES_ORDER_ID] = $connection->getCheckSql(
                $connection->prepareSqlCondition(
                    OrderInterface::SALES_ORDER_ID,
                    [ [ 'null' => true ], [ 'eq' => $salesOrderId ] ]
                ),
                $connection->quote($salesOrderId),
                $connection->quoteIdentifier(OrderInterface::SALES_ORDER_ID)
            );
        }

        return $data;
    }

    /**
     * @param int $orderId
     * @throws LocalizedException
     */
    public function bumpOrderImportTryCount($orderId)
    {
        $connection = $this->getConnection();

        $connection->update(
            $this->getMainTable(),
            [ 'import_remaining_try_count' => new \Zend_Db_Expr('import_remaining_try_count - 1') ],
            $connection->quoteInto('order_id = ?', $orderId)
            . ' AND '
            . $connection->quoteInto('import_remaining_try_count > ?', 0)
        );
    }

    /**
     * @param int|null $storeId
     * @return string[]
     */
    public function getMarketplaceList($storeId = null)
    {
        $connection = $this->getConnection();

        $marketplaceSelect = $connection->select()
            ->distinct(true)
            ->from($this->getMainTable(), [ OrderInterface::MARKETPLACE_NAME ]);

        if (null !== $storeId) {
            $marketplaceSelect->where(OrderInterface::STORE_ID . ' = ?', (int) $storeId);
        }

        return $connection->fetchCol($marketplaceSelect);
    }

    /**
     * @param int|null $storeId
     * @return array
     */
    public function getChannelMap($storeId = null)
    {
        $connection = $this->getConnection();

        $marketplaceSelect = $connection->select()
            ->distinct(true)
            ->from(
                $this->getMainTable(),
                [
                    OrderInterface::SHOPPING_FEED_MARKETPLACE_ID,
                    OrderInterface::MARKETPLACE_NAME,
                ]
            );

        if (null !== $storeId) {
            $marketplaceSelect->where(OrderInterface::STORE_ID . ' = ?', (int) $storeId);
        }

        return $connection->fetchPairs($marketplaceSelect);
    }
}
