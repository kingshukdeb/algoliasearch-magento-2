<?php

namespace Algolia\AlgoliaSearch\Helper;

use Algolia\AlgoliaSearch\Helper\Entity\AdditionalSectionHelper;
use Algolia\AlgoliaSearch\Helper\Entity\CategoryHelper;
use Algolia\AlgoliaSearch\Helper\Entity\PageHelper;
use Algolia\AlgoliaSearch\Helper\Entity\ProductHelper;
use Algolia\AlgoliaSearch\Helper\Entity\SuggestionHelper;
use AlgoliaSearch\AlgoliaException;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Search\Model\Query;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

class Data
{
    const COLLECTION_PAGE_SIZE = 100;

    private $algoliaHelper;

    private $pageHelper;
    private $categoryHelper;
    private $productHelper;
    private $suggestionHelper;
    private $additionalSectionHelper;
    private $stockRegistry;

    private $logger;
    private $configHelper;
    private $emulation;
    private $resource;
    private $eventManager;
    private $storeManager;

    private $emulationRuns = false;

    public function __construct(
        AlgoliaHelper $algoliaHelper,
        ConfigHelper $configHelper,
        ProductHelper $producthelper,
        CategoryHelper $categoryHelper,
        PageHelper $pageHelper,
        SuggestionHelper $suggestionHelper,
        AdditionalSectionHelper $additionalSectionHelper,
        StockRegistryInterface $stockRegistry,
        Emulation $emulation,
        Logger $logger,
        ResourceConnection $resource,
        ManagerInterface $eventManager,
        StoreManagerInterface $storeManager
    ) {
    
        $this->algoliaHelper = $algoliaHelper;

        $this->pageHelper = $pageHelper;
        $this->categoryHelper = $categoryHelper;
        $this->productHelper = $producthelper;
        $this->suggestionHelper = $suggestionHelper;
        $this->additionalSectionHelper = $additionalSectionHelper;
        $this->stockRegistry = $stockRegistry;

        $this->configHelper = $configHelper;
        $this->logger = $logger;
        $this->emulation = $emulation;
        $this->resource = $resource;
        $this->eventManager = $eventManager;
        $this->storeManager = $storeManager;
    }

    public function deleteObjects($storeId, $ids, $indexName)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $this->algoliaHelper->deleteObjects($ids, $indexName);
    }

    public function saveConfigurationToAlgolia($storeId, $useTmpIndex = false)
    {
        if (!($this->configHelper->getApplicationID() && $this->configHelper->getAPIKey())) {
            return;
        }

        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $this->algoliaHelper->setSettings(
            $this->getIndexName($this->categoryHelper->getIndexNameSuffix(), $storeId),
            $this->categoryHelper->getIndexSettings($storeId),
            false,
            true
        );
        $this->algoliaHelper->setSettings(
            $this->getIndexName($this->pageHelper->getIndexNameSuffix(), $storeId),
            $this->pageHelper->getIndexSettings($storeId)
        );
        $this->algoliaHelper->setSettings(
            $this->getIndexName($this->suggestionHelper->getIndexNameSuffix(), $storeId),
            $this->suggestionHelper->getIndexSettings($storeId)
        );

        $protectedSections = ['products', 'categories', 'pages', 'suggestions'];
        foreach ($this->configHelper->getAutocompleteSections() as $section) {
            if (in_array($section['name'], $protectedSections, true)) {
                continue;
            }

            $indexName = $this->getIndexName($this->additionalSectionHelper->getIndexNameSuffix(), $storeId);
            $indexName = $indexName . '_' . $section['name'];

            $this->algoliaHelper->setSettings($indexName, $this->additionalSectionHelper->getIndexSettings($storeId));
        }

        $productsIndexName = $this->getIndexName($this->productHelper->getIndexNameSuffix(), $storeId);
        $productsIndexNameTmp = $this->getIndexName($this->productHelper->getIndexNameSuffix(), $storeId, true);
        
        $this->productHelper->setSettings($productsIndexName, $productsIndexNameTmp, $storeId, $useTmpIndex);

        $this->setExtraSettings($storeId, $useTmpIndex);
    }

    public function getSearchResult($query, $storeId)
    {
        $indexName = $this->getIndexName($this->productHelper->getIndexNameSuffix(), $storeId);

        $numberOfResults = 1000;
        if ($this->configHelper->isInstantEnabled()) {
            $numberOfResults = min($this->configHelper->getNumberOfProductResults($storeId), 1000);
        }

        $answer = $this->algoliaHelper->query($indexName, $query, [
            'hitsPerPage'            => $numberOfResults, // retrieve all the hits (hard limit is 1000)
            'attributesToRetrieve'   => 'objectID',
            'attributesToHighlight'  => '',
            'attributesToSnippet'    => '',
            'numericFilters'         => 'visibility_search=1',
            'removeWordsIfNoResults' => $this->configHelper->getRemoveWordsIfNoResult($storeId),
            'analyticsTags'          => 'backend-search',
        ]);

        $data = [];

        foreach ($answer['hits'] as $i => $hit) {
            $productId = $hit['objectID'];

            if ($productId) {
                $data[$productId] = [
                    'entity_id' => $productId,
                    'score'     => $numberOfResults - $i,
                ];
            }
        }

        return $data;
    }

    public function rebuildStoreAdditionalSectionsIndex($storeId)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $additionalSections = $this->configHelper->getAutocompleteSections();

        $protectedSections = ['products', 'categories', 'pages', 'suggestions'];
        foreach ($additionalSections as $section) {
            if (in_array($section['name'], $protectedSections, true)) {
                continue;
            }

            $indexName = $this->getIndexName($this->additionalSectionHelper->getIndexNameSuffix(), $storeId);
            $indexName = $indexName . '_' . $section['name'];

            $attributeValues = $this->additionalSectionHelper->getAttributeValues($storeId, $section);

            foreach (array_chunk($attributeValues, 100) as $chunk) {
                $this->algoliaHelper->addObjects($chunk, $indexName . '_tmp');
            }

            $this->algoliaHelper->moveIndex($indexName . '_tmp', $indexName);

            $this->algoliaHelper->setSettings($indexName, $this->additionalSectionHelper->getIndexSettings($storeId));
        }
    }

    public function rebuildStorePageIndex($storeId)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $this->startEmulation($storeId);

        $indexName = $this->getIndexName($this->pageHelper->getIndexNameSuffix(), $storeId);

        $pages = $this->pageHelper->getPages($storeId);

        foreach (array_chunk($pages, 100) as $chunk) {
            $this->algoliaHelper->addObjects($chunk, $indexName . '_tmp');
        }

        $this->algoliaHelper->moveIndex($indexName . '_tmp', $indexName);

        $this->algoliaHelper->setSettings($indexName, $this->pageHelper->getIndexSettings($storeId));

        $this->stopEmulation();
    }

    public function rebuildStoreCategoryIndex($storeId, $categoryIds = null)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $this->startEmulation($storeId);

        try {
            $collection = $this->categoryHelper->getCategoryCollectionQuery($storeId, $categoryIds);

            $size = $collection->getSize();

            if ($size > 0) {
                $pages = ceil($size / $this->configHelper->getNumberOfElementByPage());
                $collection->clear();
                $page = 1;

                while ($page <= $pages) {
                    $this->rebuildStoreCategoryIndexPage(
                        $storeId,
                        $collection,
                        $page,
                        $this->configHelper->getNumberOfElementByPage()
                    );

                    $page++;
                }

                unset($indexData);
            }
        } catch (\Exception $e) {
            $this->stopEmulation();
            throw $e;
        }

        $this->stopEmulation();
    }

    public function rebuildStoreSuggestionIndex($storeId)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $collection = $this->suggestionHelper->getSuggestionCollectionQuery($storeId);

        $size = $collection->getSize();

        if ($size > 0) {
            $pages = ceil($size / $this->configHelper->getNumberOfElementByPage());
            $collection->clear();
            $page = 1;

            while ($page <= $pages) {
                $this->rebuildStoreSuggestionIndexPage(
                    $storeId,
                    $collection,
                    $page,
                    $this->configHelper->getNumberOfElementByPage()
                );

                $page++;
            }

            unset($indexData);
        }

        $this->moveStoreSuggestionIndex($storeId);
    }

    public function moveIndex($tmpIndexName, $indexName)
    {
        if ($this->isIndexingEnabled() === false) {
            return;
        }

        $this->algoliaHelper->moveIndex($tmpIndexName, $indexName);
    }

    public function moveStoreSuggestionIndex($storeId)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $indexNameSuffix = $this->suggestionHelper->getIndexNameSuffix();
        $tmpIndexName = $this->getIndexName($indexNameSuffix, $storeId, true);
        $indexName = $this->getIndexName($indexNameSuffix, $storeId);

        $this->algoliaHelper->moveIndex($tmpIndexName, $indexName);
    }

    public function rebuildStoreProductIndex($storeId, $productIds)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $this->startEmulation($storeId);

        $this->logger->start('Indexing');
        try {
            $this->logger->start('ok');

            $collection = $this->productHelper->getProductCollectionQuery($storeId, $productIds);
            $size = $collection->getSize();

            if ($productIds && $productIds !== []) {
                $size = max(count($productIds), $size);
            }

            $this->logger->log('Store ' . $this->logger->getStoreName($storeId) . ' collection size : ' . $size);

            if ($size > 0) {
                $pages = ceil($size / $this->configHelper->getNumberOfElementByPage());
                $collection->clear();
                $page = 1;

                while ($page <= $pages) {
                    $this->rebuildStoreProductIndexPage(
                        $storeId,
                        $collection,
                        $page,
                        $this->configHelper->getNumberOfElementByPage(),
                        null,
                        $productIds
                    );

                    $page++;
                }
            }
        } catch (\Exception $e) {
            $this->stopEmulation();
            throw $e;
        }
        $this->logger->stop('Indexing');

        $this->stopEmulation();
    }

    public function rebuildProductIndex($storeId, $productIds, $page, $pageSize, $useTmpIndex)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $collection = $this->productHelper->getProductCollectionQuery($storeId, null, $useTmpIndex);
        $this->rebuildStoreProductIndexPage($storeId, $collection, $page, $pageSize, null, $productIds, $useTmpIndex);
    }

    public function rebuildStoreSuggestionIndexPage($storeId, $collectionDefault, $page, $pageSize)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        /** @var \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection $collection */
        $collection = clone $collectionDefault;
        $collection->setCurPage($page)->setPageSize($pageSize);
        $collection->load();

        $indexName = $this->getIndexName($this->suggestionHelper->getIndexNameSuffix(), $storeId, true);

        $indexData = [];

        /** @var Query $suggestion */
        foreach ($collection as $suggestion) {
            $suggestion->setStoreId($storeId);

            $suggestionObject = $this->suggestionHelper->getObject($suggestion);

            if (strlen($suggestionObject['query']) >= 3) {
                array_push($indexData, $suggestionObject);
            }
        }

        if (count($indexData) > 0) {
            $this->algoliaHelper->addObjects($indexData, $indexName);
        }

        unset($indexData);

        $collection->walk('clearInstance');
        $collection->clear();

        unset($collection);
    }

    public function rebuildStoreCategoryIndexPage($storeId, $collectionDefault, $page, $pageSize)
    {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = clone $collectionDefault;
        $collection->setCurPage($page)->setPageSize($pageSize);
        $collection->load();

        $indexName = $this->getIndexName($this->categoryHelper->getIndexNameSuffix(), $storeId);

        $indexData = [];

        /** @var Category $category */
        foreach ($collection as $category) {
            if (!$this->categoryHelper->isCategoryActive($category->getId(), $storeId)) {
                continue;
            }

            $category->setStoreId($storeId);

            $categoryObject = $this->categoryHelper->getObject($category);

            if ($this->configHelper->shouldIndexEmptyCategories($storeId) === true
                || $categoryObject['product_count'] > 0) {
                array_push($indexData, $categoryObject);
            }
        }

        if (count($indexData) > 0) {
            $this->algoliaHelper->addObjects($indexData, $indexName);
        }

        unset($indexData);

        $collection->walk('clearInstance');
        $collection->clear();

        unset($collection);
    }

    private function getProductsRecords($storeId, $collection, $potentiallyDeletedProductsIds = null)
    {
        $productsToIndex = [];
        $productsToRemove = [];

        // In $potentiallyDeletedProductsIds there might be IDs of deleted products which will not be in a collection
        if (is_array($potentiallyDeletedProductsIds)) {
            $potentiallyDeletedProductsIds = array_combine(
                $potentiallyDeletedProductsIds,
                $potentiallyDeletedProductsIds
            );
        }

        $this->logger->start('CREATE RECORDS ' . $this->logger->getStoreName($storeId));
        $this->logger->log(count($collection) . ' product records to create');

        $salesData = $this->getSalesData($storeId, $collection);

        /** @var Product $product */
        foreach ($collection as $product) {
            $product->setStoreId($storeId);
            $product->setPriceCalculation(false);

            $productId = $product->getId();

            // If $productId is in the collection, remove it from $potentiallyDeletedProductsIds
            // so it's not removed without check
            if (isset($potentiallyDeletedProductsIds[$productId])) {
                unset($potentiallyDeletedProductsIds[$productId]);
            }

            if (isset($productsToIndex[$productId]) || isset($productsToRemove[$productId])) {
                continue;
            }

            if ($this->productCanBeReindexed($product, $storeId) === false) {
                $productsToRemove[$productId] = $productId;
                continue;
            }

            if (isset($salesData[$productId])) {
                $product->setData('ordered_qty', $salesData[$productId]['ordered_qty']);
                $product->setData('total_ordered', $salesData[$productId]['total_ordered']);
            }

            $productsToIndex[$productId] = $this->productHelper->getObject($product);
        }

        if (is_array($potentiallyDeletedProductsIds)) {
            $productsToRemove = array_merge($productsToRemove, $potentiallyDeletedProductsIds);
        }

        $this->logger->stop('CREATE RECORDS ' . $this->logger->getStoreName($storeId));

        return [
            'toIndex' => $productsToIndex,
            'toRemove' => array_unique($productsToRemove),
        ];
    }


    public function productCanBeReindexed($product, $storeId, $throwExceptions = false)
    {
        if ($product->isDeleted() === true) {
            if ($throwExceptions === true) {
                throw new \Exception(
                    __(
                        'The product "%1" (%2) is deleted (store %3)',
                        [$product->getName(), $product->getSku(), $storeId]
                    )
                );
            }
            return false;
        }

        if ($product->getStatus() == Status::STATUS_DISABLED) {
            if ($throwExceptions === true) {
                throw new \Exception(
                    __(
                        'The product "%1" (%2) is disabled (store %3)',
                        [$product->getName(), $product->getSku(), $storeId]
                    )
                );
            }
            return false;
        }

        if (!in_array($product->getVisibility(), [
            Visibility::VISIBILITY_BOTH,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_IN_CATALOG,
        ])) {
            if ($throwExceptions === true) {
                throw new \Exception(
                    __(
                        'The product "%1" (%2) is not visible individually (store %3)',
                        [$product->getName(), $product->getSku(), $storeId]
                    )
                );
            }
            return false;
        }

        if (!$this->configHelper->getShowOutOfStock($storeId)) {
            $stockItem = $this->stockRegistry->getStockItem($product->getId());
            if (!$product->isSalable() || !$stockItem->getIsInStock()) {
                if ($throwExceptions === true) {
                    throw new \Exception(
                        __(
                            'The product "%1" (%2) is out of stock (store %3)',
                            [$product->getName(), $product->getSku(), $storeId]
                        )
                    );
                }
                return false;
            }
        }

        return true;
    }

    public function rebuildStoreProductIndexPage(
        $storeId,
        $collectionDefault,
        $page,
        $pageSize,
        $emulationInfo = null,
        $productIds = null,
        $useTmpIndex = false
    ) {
        if ($this->isIndexingEnabled($storeId) === false) {
            return;
        }

        $wrapperLogMessage = 'rebuildStoreProductIndexPage: ' . $this->logger->getStoreName($storeId) . ', 
            page ' . $page . ', 
            pageSize ' . $pageSize;
        $this->logger->start($wrapperLogMessage);

        if ($emulationInfo === null) {
            $this->startEmulation($storeId);
        }

        $additionalAttributes = $this->configHelper->getProductAdditionalAttributes($storeId);

        /** @var Collection $collection */
        $collection = clone $collectionDefault;

        $collection->setCurPage($page)->setPageSize($pageSize);
        $collection->addCategoryIds();
        $collection->addUrlRewrite();

        if ($this->productHelper->isAttributeEnabled($additionalAttributes, 'rating_summary')) {
            $reviewTableName = $this->resource->getTableName('review_entity_summary');
            $collection
                ->getSelect()
                ->columns('(SELECT MAX(rating_summary) FROM ' . $reviewTableName . ' AS o WHERE o.entity_pk_value = e.entity_id AND o.store_id = '.$storeId.') as rating_summary');
        }

        $this->eventManager->dispatch(
            'algolia_before_products_collection_load',
            ['collection' => $collection, 'store' => $storeId]
        );

        $logMessage = 'LOADING: ' . $this->logger->getStoreName($storeId) . ', 
            collection page: ' . $page . ', 
            pageSize: ' . $pageSize;

        $this->logger->start($logMessage);

        $collection->load();

        $this->logger->log('Loaded ' . count($collection) . ' products');
        $this->logger->stop($logMessage);

        $indexName = $this->getIndexName($this->productHelper->getIndexNameSuffix(), $storeId, $useTmpIndex);

        $indexData = $this->getProductsRecords($storeId, $collection, $productIds);

        if ($indexData['toIndex'] && $indexData['toIndex'] !== []) {
            $this->logger->start('ADD/UPDATE TO ALGOLIA');

            $this->algoliaHelper->addObjects($indexData['toIndex'], $indexName);

            $this->logger->log('Product IDs: ' . implode(', ', array_keys($indexData['toIndex'])));
            $this->logger->stop('ADD/UPDATE TO ALGOLIA');
        }

        if ($indexData['toRemove'] && $indexData['toRemove'] !== []) {
            $toRealRemove = $this->getIdsToRealRemove($indexName, $indexData['toRemove']);

            if ($toRealRemove && $toRealRemove !== []) {
                $this->logger->start('REMOVE FROM ALGOLIA');

                $this->algoliaHelper->deleteObjects($toRealRemove, $indexName);

                $this->logger->log('Product IDs: '.implode(', ', $toRealRemove));
                $this->logger->stop('REMOVE FROM ALGOLIA');
            }
        }

        unset($indexData);

        $collection->walk('clearInstance');
        $collection->clear();

        unset($collection);

        if ($emulationInfo === null) {
            $this->stopEmulation();
        }

        $this->logger->stop($wrapperLogMessage);
    }

    public function startEmulation($storeId)
    {
        if ($this->emulationRuns === true) {
            return;
        }

        $this->logger->start('START EMULATION');

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        $this->emulationRuns = true;

        $this->logger->stop('START EMULATION');
    }

    public function stopEmulation()
    {
        $this->logger->start('STOP EMULATION');

        $this->emulation->stopEnvironmentEmulation();
        $this->emulationRuns = false;

        $this->logger->stop('STOP EMULATION');
    }

    public function isIndexingEnabled($storeId = null)
    {
        if ($this->configHelper->isEnabledBackend($storeId) === false) {
            $this->logger->log('INDEXING IS DISABLED FOR ' . $this->logger->getStoreName($storeId));

            return false;
        }

        return true;
    }

    private function getIdsToRealRemove($indexName, $idsToRemove)
    {
        if (count($idsToRemove) === 1) {
            return $idsToRemove;
        }

        $toRealRemove = [];

        $idsToRemove = array_map('strval', $idsToRemove);

        foreach (array_chunk($idsToRemove, 1000) as $chunk) {
            $objects = $this->algoliaHelper->getObjects($indexName, $chunk);
            foreach ($objects['results'] as $object) {
                if (isset($object['objectID'])) {
                    $toRealRemove[] = $object['objectID'];
                }
            }
        }

        return $toRealRemove;
    }

    private function setExtraSettings($storeId, $saveToTmpIndicesToo)
    {
        $additionalSectionsSuffix = $this->additionalSectionHelper->getIndexNameSuffix();

        $sections = [
            'products' => $this->getIndexName($this->productHelper->getIndexNameSuffix(), $storeId),
            'categories' => $this->getIndexName($this->categoryHelper->getIndexNameSuffix(), $storeId),
            'pages' => $this->getIndexName($this->pageHelper->getIndexNameSuffix(), $storeId),
            'suggestions' => $this->getIndexName($this->suggestionHelper->getIndexNameSuffix(), $storeId),
            'additional_sections' => $this->getIndexName($additionalSectionsSuffix, $storeId),
        ];

        $error = [];
        foreach ($sections as $section => $indexName) {
            try {
                $extraSettings = $this->configHelper->getExtraSettings($section, $storeId);

                if ($extraSettings) {
                    $extraSettings = json_decode($extraSettings, true);

                    $this->algoliaHelper->setSettings($indexName, $extraSettings, true);

                    if ($section === 'products' && $saveToTmpIndicesToo === true) {
                        $this->algoliaHelper->setSettings($indexName.'_tmp', $extraSettings, true);
                    }
                }
            } catch (AlgoliaException $e) {
                if (strpos($e->getMessage(), 'Invalid object attributes:') === 0) {
                    $error[] = '
                        Extra settings for "'.$section.'" indices were not saved. 
                        Error message: "'.$e->getMessage().'"';

                    continue;
                }

                throw $e;
            }
        }

        if ($error) {
            throw new AlgoliaException('<br>'.implode('<br> ', $error));
        }
    }

    private function getSalesData($storeId, Collection $collection)
    {
        $additionalAttributes = $this->configHelper->getProductAdditionalAttributes($storeId);
        if ($this->productHelper->isAttributeEnabled($additionalAttributes, 'ordered_qty') === false
            && $this->productHelper->isAttributeEnabled($additionalAttributes, 'total_ordered') === false) {
            return [];
        }

        $ordersTableName = $this->resource->getTableName('sales_order_item');

        $ids = $collection->getAllIds();
        $ids[] = '0'; // Makes sure the imploded string is not empty

        $ids = implode(', ', $ids);

        try {
            $salesConnection = $this->resource->getConnectionByName('sales');
        } catch (\DomainException $e) {
            $salesConnection = $this->resource->getConnection();
        }

        $query = 'SELECT product_id, SUM(qty_ordered) AS ordered_qty, SUM(row_total) AS total_ordered 
            FROM ' . $ordersTableName . ' 
            WHERE product_id IN (' . $ids . ') 
            GROUP BY product_id';
        $salesData = $salesConnection->query($query)->fetchAll(\PDO::FETCH_GROUP|\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);

        return $salesData;
    }

    public function deleteInactiveProducts($storeId)
    {
        $indexName = $this->getIndexName($this->productHelper->getIndexNameSuffix(), $storeId);
        $index = $this->algoliaHelper->getIndex($indexName);

        $objectIds = [];
        $counter = 0;
        foreach ($index->browse('', ['attributesToRetrieve' => ['objectID']]) as $hit) {
            $objectIds[] = $hit['objectID'];
            $counter++;

            if ($counter === 1000) {
                $this->deleteInactiveIds($storeId, $objectIds, $indexName);

                $objectIds = [];
                $counter = 0;
            }
        }

        if ($objectIds && $objectIds !== []) {
            $this->deleteInactiveIds($storeId, $objectIds, $indexName);
        }
    }

    public function getIndexName($indexSuffix, $storeId = null, $tmp = false)
    {
        return $this->getBaseIndexName($storeId) . $indexSuffix . ($tmp ? '_tmp' : '');
    }

    public function getBaseIndexName($storeId = null)
    {
        return $this->configHelper->getIndexPrefix($storeId) . $this->storeManager->getStore($storeId)->getCode();
    }

    private function deleteInactiveIds($storeId, $objectIds, $indexName)
    {
        $collection = $this->productHelper->getProductCollectionQuery($storeId, $objectIds);
        $dbIds = $collection->getAllIds();

        $collection = null;

        $idsToDeleteFromAlgolia = array_diff($objectIds, $dbIds);
        $this->algoliaHelper->deleteObjects($idsToDeleteFromAlgolia, $indexName);
    }
}
