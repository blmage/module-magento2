<?php

namespace ShoppingFeed\Manager\Model\Feed\Product;

use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\Form\Element\DataType\Number as UiNumber;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Feed\ProductInterface as FeedProductInterface;
use ShoppingFeed\Manager\DataObject;
use ShoppingFeed\Manager\Model\Config\Field\Checkbox;
use ShoppingFeed\Manager\Model\Config\Field\Select;
use ShoppingFeed\Manager\Model\Config\Field\TextBox;
use ShoppingFeed\Manager\Model\Config\Value\Handler\Option as OptionHandler;
use ShoppingFeed\Manager\Model\Config\Value\Handler\PositiveInteger as PositiveIntegerHandler;
use ShoppingFeed\Manager\Model\Feed\AbstractConfig;

abstract class RefreshableConfig extends AbstractConfig implements RefreshableConfigInterface
{
    const KEY_FORCE_PRODUCT_LOAD_FOR_REFRESH = 'force_product_load_for_refresh';
    const KEY_AUTOMATIC_REFRESH_STATE = 'automatic_refresh_state';
    const KEY_AUTOMATIC_REFRESH_DELAY = 'automatic_refresh_delay';
    const KEY_ENABLE_ADVISED_REFRESH_REQUIREMENT = 'enable_advised_refresh_requirement';
    const KEY_ADVISED_REFRESH_REQUIREMENT_DELAY = 'advised_refresh_requirement_delay';

    protected function getBaseFields()
    {
        // Note: we can not use big sort orders here because each index between 1 and the defined value
        // will actually be tested on the browser side, multiple times.

        return [
            $this->fieldFactory->create(
                Checkbox::TYPE_CODE,
                [
                    'name' => self::KEY_FORCE_PRODUCT_LOAD_FOR_REFRESH,
                    'label' => __('Force Full Loading of Products for Refresh'),
                    'sortOrder' => 100010,
                    'checkedNotice' => $this->getTranslatedMultiLineString(
                        [
                            'Products will be loaded individually, with all their data.',
                            'This method is (much) slower, and should therefore only be used if the other is insufficient.'
                        ]
                    ),
                    'uncheckedNotice' => $this->getTranslatedMultiLineString(
                        [
                            'Products will be loaded in batch, with only the necessary data.',
                            'This method is (much) faster, but in some rare cases insufficient to fetch specific data.'
                        ]
                    ),
                ]
            ),

            $this->fieldFactory->create(
                Select::TYPE_CODE,
                [
                    'name' => self::KEY_AUTOMATIC_REFRESH_STATE,
                    'valueHandler' => $this->valueHandlerFactory->create(
                        OptionHandler::TYPE_CODE,
                        [
                            'dataType' => UiNumber::NAME,
                            'hasEmptyOption' => true,
                            'optionArray' => [
                                [ 'value' => '', 'label' => __('No') ],
                                [ 'value' => FeedProductInterface::REFRESH_STATE_ADVISED, 'label' => __('Advised') ],
                                [ 'value' => FeedProductInterface::REFRESH_STATE_REQUIRED, 'label' => __('Required') ],
                            ],
                        ]
                    ),
                    'defaultFormValue' => '',
                    'defaultUseValue' => '',
                    'label' => __('Force Automatic Refresh'),
                    'notice' => $this->getTranslatedMultiLineString(
                        [
                            'Indicates whether to refresh the section data on a regular basis:',
                            '- "No": data will only be refreshed when updates are detected.',
                            '- "Advised" / "Required": data will also be refreshed after a specific amount of time.',
                            '- "Required": takes priority over "Advised" refresh, and is enforced before any generation of the feed.'
                        ]
                    ),
                    'dependencies' => [
                        [
                            'values' => [
                                FeedProductInterface::REFRESH_STATE_ADVISED,
                                FeedProductInterface::REFRESH_STATE_REQUIRED,
                            ],
                            'fieldNames' => [ self::KEY_AUTOMATIC_REFRESH_DELAY ],
                        ],
                    ],
                    'sortOrder' => 100020,
                ]
            ),

            $this->fieldFactory->create(
                TextBox::TYPE_CODE,
                [
                    'name' => self::KEY_AUTOMATIC_REFRESH_DELAY,
                    'valueHandler' => $this->valueHandlerFactory->create(PositiveIntegerHandler::TYPE_CODE),
                    'isRequired' => true,
                    'label' => __('Force Automatic Refresh After'),
                    'notice' => __('In minutes.'),
                    'sortOrder' => 100030,
                ]
            ),

            // @todo (requires specific filters)
            /*
            $this->fieldFactory->create(
                Checkbox::TYPE_CODE,
                [
                    'name' => self::KEY_ENABLE_ADVISED_REFRESH_REQUIREMENT,
                    'label' => __('Require Advised Refresh'),
                    'checkedDependentFieldNames' => [ self::KEY_ADVISED_REFRESH_REQUIREMENT_DELAY ],
                    'sortOrder' => 100040,
                ]
            ),

            $this->fieldFactory->create(
                TextBox::TYPE_CODE,
                [
                    'name' => self::KEY_ADVISED_REFRESH_REQUIREMENT_DELAY,
                    'valueHandler' => $this->valueHandlerFactory->create(PositiveIntegerHandler::TYPE_CODE),
                    'required' => true,
                    'label' => __('Require Advised Refresh After'),
                    'notice' => __('In minutes.'),
                    'sortOrder' => 100050,
                ]
            ),
            */
        ];
    }

    public function shouldForceProductLoadForRefresh(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_FORCE_PRODUCT_LOAD_FOR_REFRESH);
    }

    public function getAutomaticRefreshState(StoreInterface $store)
    {
        $state = $this->getFieldValue($store, self::KEY_AUTOMATIC_REFRESH_STATE);
        return empty($state) ? false : $state;
    }

    public function getAutomaticRefreshDelay(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_AUTOMATIC_REFRESH_DELAY) * 60;
    }

    public function isAdvisedRefreshRequirementEnabled(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_ENABLE_ADVISED_REFRESH_REQUIREMENT);
    }

    public function getAdvisedRefreshRequirementDelay(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_ADVISED_REFRESH_REQUIREMENT_DELAY) * 60;
    }

    /**
     * @param StoreInterface $store
     * @param DataObject $dataA
     * @param DataObject $dataB
     * @return bool
     * @throws LocalizedException
     */
    public function isRefreshNeededForStoreDataChange(StoreInterface $store, DataObject $dataA, DataObject $dataB)
    {
        $irrelevantFieldNames = [
            self::KEY_FORCE_PRODUCT_LOAD_FOR_REFRESH,
            self::KEY_AUTOMATIC_REFRESH_STATE,
            self::KEY_AUTOMATIC_REFRESH_DELAY,
            self::KEY_ENABLE_ADVISED_REFRESH_REQUIREMENT,
            self::KEY_ADVISED_REFRESH_REQUIREMENT_DELAY,
        ];

        $cleanDataA = clone $dataA;
        $cleanDataB = clone $dataB;

        foreach ($irrelevantFieldNames as $fieldName) {
            $fieldValuePath = $this->getFieldValuePath($fieldName);
            $cleanDataA->unsetDataByPath($fieldValuePath);
            $cleanDataB->unsetDataByPath($fieldValuePath);
        }

        return !$this->isEqualStoreData($store, $cleanDataA, $cleanDataB);
    }
}
