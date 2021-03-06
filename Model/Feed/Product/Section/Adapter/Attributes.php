<?php

namespace ShoppingFeed\Manager\Model\Feed\Product\Section\Adapter;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Eav\Model\Entity\Type as EavEntityType;
use Magento\Eav\Model\Entity\TypeFactory as EavEntityTypeFactory;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use ShoppingFeed\Feed\Product\AbstractProduct as AbstractExportedProduct;
use ShoppingFeed\Feed\Product\Product as ExportedProduct;
use ShoppingFeed\Feed\Product\ProductVariation as ExportedVariation;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Attribute\SourceInterface as AttributeSourceInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Attribute\Value\RendererPoolInterface as AttributeRendererPoolInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\AbstractAdapter;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Config\AttributesInterface as ConfigInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Type\Attributes as Type;
use ShoppingFeed\Manager\Model\Feed\RefreshableProduct;
use ShoppingFeed\Manager\Model\LabelledValueFactory;

/**
 * @method ConfigInterface getConfig()
 */
class Attributes extends AbstractAdapter implements AttributesInterface
{
    const KEY_SKU = 'sku';
    const KEY_NAME = 'name';
    const KEY_GTIN = 'gtin';
    const KEY_BRAND = 'brand';
    const KEY_DESCRIPTION = 'description';
    const KEY_SHORT_DESCRIPTION = 'short_description';
    const KEY_URL = 'url';
    const KEY_ATTRIBUTE_MAP = 'attribute_map';
    const KEY_CONFIGURABLE_ATTRIBUTES = 'configurable_attributes';
    const KEY_ATTRIBUTE_SET = 'attribute_set';

    /**
     * @var EavEntityTypeFactory
     */
    private $eavEntityTypeFactory;

    /**
     * @var UrlInterface
     */
    private $frontendUrlBuilder;

    /**
     * @var AttributeSourceInterface
     */
    private $configurableAttributeSource;

    /**
     * @var EavEntityType|null
     */
    private $productEavEntityType = null;

    /**
     * @var string[]|null
     */
    private $attributeSetNames = null;

    /**
     * @param StoreManagerInterface $storeManager
     * @param EavEntityTypeFactory $eavEntityTypeFactory
     * @param LabelledValueFactory $labelledValueFactory
     * @param AttributeRendererPoolInterface $attributeRendererPool
     * @param UrlInterface $frontendUrlBuilder
     * @param AttributeSourceInterface $configurableAttributeSource
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        EavEntityTypeFactory $eavEntityTypeFactory,
        LabelledValueFactory $labelledValueFactory,
        AttributeRendererPoolInterface $attributeRendererPool,
        UrlInterface $frontendUrlBuilder,
        AttributeSourceInterface $configurableAttributeSource
    ) {
        $this->eavEntityTypeFactory = $eavEntityTypeFactory;
        $this->frontendUrlBuilder = $frontendUrlBuilder;
        $this->configurableAttributeSource = $configurableAttributeSource;
        parent::__construct($storeManager, $labelledValueFactory, $attributeRendererPool);
    }

    public function getSectionType()
    {
        return Type::CODE;
    }

    public function prepareLoadableProductCollection(StoreInterface $store, ProductCollection $productCollection)
    {
        $productCollection->addAttributeToSelect([ 'sku', 'name', 'url_key' ]);

        foreach ($this->getConfig()->getAllAttributes($store) as $attribute) {
            $productCollection->addAttributeToSelect($attribute->getAttributeCode());
        }

        foreach ($this->configurableAttributeSource->getAttributesByCode() as $code => $attribute) {
            $productCollection->addAttributeToSelect($code);
        }

        $productCollection->addUrlRewrite();
    }

    public function prepareLoadedProductCollection(StoreInterface $store, ProductCollection $productCollection)
    {
        $weeeAttributes = [];

        foreach ($this->getConfig()->getAllAttributes($store) as $attribute) {
            if ('weee' === $attribute->getFrontendInput()) {
                $weeeAttributes[] = $attribute;
            }
        }

        if (!empty($weeeAttributes)) {
            /** @var CatalogProduct $product */
            foreach ($productCollection as $product) {
                foreach ($weeeAttributes as $weeeAttribute) {
                    try {
                        $weeeAttribute->getBackend()->afterLoad($product);
                    } catch (\Exception $e) {
                        // The attribute backend is invalid, not exporting anything is fine then.
                    }
                }
            }
        }
    }

    /**
     * @param StoreInterface $store
     * @param CatalogProduct $product
     * @return string
     */
    public function getCatalogProductFrontendUrl(StoreInterface $store, CatalogProduct $product)
    {
        $this->frontendUrlBuilder->setScope($store->getBaseStoreId());

        $requestPath = null;
        $urlDataObject = $product->getDataByKey('url_data_object');

        if ($urlDataObject instanceof DataObject) {
            $requestPath = trim($urlDataObject->getData('url_rewrite'));
        }

        if (empty($requestPath)) {
            // Force the initialization of the request path, if possible.
            /** @see \Magento\Catalog\Model\Product\Url::getUrl() */
            $product->getProductUrl(false);
            $requestPath = trim($product->getRequestPath());
        }

        $routeParameters = [
            '_nosid' => true,
            '_scope' => $store->getBaseStoreId(),
        ];

        if (!empty($requestPath)) {
            $routePath = '';
            $routeParameters['_direct'] = $requestPath;
        } else {
            $routePath = 'catalog/product/view';
            $routeParameters['id'] = $product->getId();
            $routeParameters['s'] = $product->getUrlKey();
        }

        return $this->frontendUrlBuilder->getUrl($routePath, $routeParameters);
    }

    /**
     * @return EavEntityType
     */
    private function getProductEavEntityType()
    {
        if (null === $this->productEavEntityType) {
            $this->productEavEntityType = $this->eavEntityTypeFactory->create();
            $this->productEavEntityType->loadByCode(CatalogProduct::ENTITY);
        }

        return $this->productEavEntityType;
    }

    /**
     * @param int $attributeSetId
     * @return string
     */
    public function getAttributeSetName($attributeSetId)
    {
        if (null === $this->attributeSetNames) {
            $this->attributeSetNames = $this->getProductEavEntityType()
                ->getAttributeSetCollection()
                ->toOptionHash();
        }

        return isset($this->attributeSetNames[$attributeSetId]) ? trim($this->attributeSetNames[$attributeSetId]) : '';
    }

    public function getProductData(StoreInterface $store, RefreshableProduct $product)
    {
        $config = $this->getConfig();
        $catalogProduct = $product->getCatalogProduct();
        $productId = (int) $catalogProduct->getId();
        $productSku = $catalogProduct->getSku();

        $data = [
            self::KEY_SKU => $config->shouldUseProductIdForSku($store) ? $productId : $productSku,
            self::KEY_NAME => $catalogProduct->getName(),
            self::KEY_URL => $this->getCatalogProductFrontendUrl($store, $catalogProduct),
        ];

        if ($attribute = $config->getBrandAttribute($store)) {
            $data[self::KEY_BRAND] = $this->getCatalogProductAttributeValue($store, $catalogProduct, $attribute);
        }

        if ($attribute = $config->getDescriptionAttribute($store)) {
            $data[self::KEY_DESCRIPTION] = $this->getCatalogProductAttributeValue($store, $catalogProduct, $attribute);
        }

        if ($attribute = $config->getShortDescriptionAttribute($store)) {
            $data[self::KEY_SHORT_DESCRIPTION] = $this->getCatalogProductAttributeValue(
                $store,
                $catalogProduct,
                $attribute
            );
        }

        if ($attribute = $config->getGtinAttribute($store)) {
            $data[self::KEY_GTIN] = $this->getCatalogProductAttributeValue($store, $catalogProduct, $attribute);
        }

        foreach ($config->getAttributeMap($store) as $key => $attribute) {
            $value = $this->getCatalogProductAttributeValue($store, $catalogProduct, $attribute);
            $data[self::KEY_ATTRIBUTE_MAP][$key] = $value;
        }

        if (ProductType::TYPE_SIMPLE === $catalogProduct->getTypeId()) {
            foreach ($this->configurableAttributeSource->getAttributesByCode() as $code => $attribute) {
                $value = $this->getCatalogProductAttributeValue($store, $catalogProduct, $attribute);
                $data[self::KEY_CONFIGURABLE_ATTRIBUTES][$code] = $value;
            }
        }

        if ($config->shouldExportAttributeSetName($store)) {
            $data[self::KEY_ATTRIBUTE_SET] = $this->getAttributeSetName($catalogProduct->getAttributeSetId());
        }

        return $data;
    }

    public function exportBaseProductData(
        StoreInterface $store,
        array $productData,
        AbstractExportedProduct $exportedProduct
    ) {
        if (isset($productData[self::KEY_SKU])) {
            $exportedProduct->setReference($productData[self::KEY_SKU]);
        }

        if (isset($productData[self::KEY_GTIN])) {
            $exportedProduct->setGtin($productData[self::KEY_GTIN]);
        }

        if (isset($productData[self::KEY_ATTRIBUTE_MAP])) {
            foreach ($this->getConfig()->getAttributeMap($store) as $key => $attribute) {
                if (isset($productData[self::KEY_ATTRIBUTE_MAP][$key])) {
                    $value = $productData[self::KEY_ATTRIBUTE_MAP][$key];

                    if (is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            $exportedProduct->setAttribute($key . '-' . $subKey, $subValue);
                        }
                    } else {
                        $exportedProduct->setAttribute($key, $productData[self::KEY_ATTRIBUTE_MAP][$key]);
                    }
                }
            }
        }

        if (isset($productData[self::KEY_ATTRIBUTE_SET])) {
            $exportedProduct->setAttribute(self::KEY_ATTRIBUTE_SET, $productData[self::KEY_ATTRIBUTE_SET]);
        }
    }

    public function exportMainProductData(
        StoreInterface $store,
        array $productData,
        ExportedProduct $exportedProduct
    ) {
        if (isset($productData[self::KEY_NAME])) {
            $exportedProduct->setName($productData[self::KEY_NAME]);
        }

        if (isset($productData[self::KEY_BRAND])) {
            $exportedProduct->setBrand($productData[self::KEY_BRAND]);
        }

        if (isset($productData[self::KEY_DESCRIPTION])) {
            $exportedProduct->setDescription(
                $productData[self::KEY_DESCRIPTION],
                $productData[self::KEY_SHORT_DESCRIPTION] ?? ''
            );
        }

        if (isset($productData[self::KEY_URL])) {
            $exportedProduct->setLink($productData[self::KEY_URL]);
            $exportedProduct->setAttribute('url', $productData[self::KEY_URL]);
        }
    }

    public function exportVariationProductData(
        StoreInterface $store,
        array $productData,
        array $configurableAttributeCodes,
        ExportedVariation $exportedVariation
    ) {
        if (isset($productData[self::KEY_CONFIGURABLE_ATTRIBUTES])) {
            foreach ($configurableAttributeCodes as $attributeCode) {
                if (isset($productData[self::KEY_CONFIGURABLE_ATTRIBUTES][$attributeCode])) {
                    $exportedVariation->setAttribute(
                        $attributeCode,
                        $productData[self::KEY_CONFIGURABLE_ATTRIBUTES][$attributeCode]
                    );
                }
            }
        }
    }

    public function describeProductData(StoreInterface $store, array $productData)
    {
        $data = $this->describeRawProductData(
            [
                self::KEY_SKU => __('SKU'),
                self::KEY_NAME => __('Name'),
                self::KEY_GTIN => __('GTIN'),
                self::KEY_ATTRIBUTE_SET => __('Attribute Set'),
                self::KEY_BRAND => __('Brand'),
                self::KEY_URL => __('URL'),
                self::KEY_DESCRIPTION => __('Description'),
                self::KEY_SHORT_DESCRIPTION => __('Short Description'),
            ],
            $productData
        );

        if (isset($productData[self::KEY_ATTRIBUTE_MAP])) {
            foreach ($this->getConfig()->getAttributeMap($store) as $key => $attribute) {
                if (isset($productData[self::KEY_ATTRIBUTE_MAP][$key])) {
                    $data[] = $this->createLabelledValue(
                        __('Attribute %1', $key),
                        $productData[self::KEY_ATTRIBUTE_MAP][$key]
                    );
                }
            }
        }

        return $data;
    }
}
