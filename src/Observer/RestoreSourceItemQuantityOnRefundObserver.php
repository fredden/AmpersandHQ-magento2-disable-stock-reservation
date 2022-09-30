<?php

declare(strict_types=1);

namespace Ampersand\DisableStockReservation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySales\Model\ReturnProcessor\GetSalesChannelForOrder;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventorySourceDeductionApi\Model\ItemToDeductFactory;
use Magento\InventorySourceDeductionApi\Model\SourceDeductionRequestFactory;
use Magento\InventorySourceDeductionApi\Model\SourceDeductionServiceInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;

class RestoreSourceItemQuantityOnRefundObserver implements ObserverInterface
{
    /**
     * @var GetSkuFromOrderItemInterface
     */
    private GetSkuFromOrderItemInterface $getSkuFromOrderItem;

    /**
     * @var IsSourceItemManagementAllowedForProductTypeInterface
     */
    private IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType;

    /**
     * @var GetProductTypesBySkusInterface
     */
    private GetProductTypesBySkusInterface $getProductTypesBySkus;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var DefaultSourceProviderInterface
     */
    private DefaultSourceProviderInterface $defaultSourceProvider;


    /**
     * @var GetSourcesAssignedToStockOrderedByPriorityInterface
     */
    private GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStockOrderedByPriority;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver;


    /**
     * @var SourceDeductionRequestFactory
     */
    private SourceDeductionRequestFactory $sourceDeductionRequestFactory;

    /**
     * @var SalesEventExtensionFactory;
     */
    private SalesEventExtensionFactory $salesEventExtensionFactory;

    /**
     * @var GetSalesChannelForOrder
     */
    private GetSalesChannelForOrder $getSalesChannelForOrder;


    /**
     * @var SourceDeductionServiceInterface
     */
    private SourceDeductionServiceInterface $sourceDeductionService;


    /**
     * @var GetSourceItemsBySkuInterface
     */
    private GetSourceItemsBySkuInterface $getSourceItemsBySku;

    /**
     * @var SalesEventInterfaceFactory
     */
    private SalesEventInterfaceFactory $salesEventFactory;

    /**
     * @var ItemToDeductFactory
     */
    private ItemToDeductFactory $itemToDeductFactory;

    /**
     * RestoreSourceItemQuantityOnRefundObserver constructor.
     *
     * @param GetSkuFromOrderItemInterface $getSkuFromOrderItem
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType
     * @param GetProductTypesBySkusInterface $getProductTypesBySkus
     * @param OrderRepositoryInterface $orderRepository
     * @param DefaultSourceProviderInterface $defaultSourceProvider
     * @param GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStockOrderedByPriority
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param SourceDeductionRequestFactory $sourceDeductionRequestFactory
     * @param SalesEventExtensionFactory $salesEventExtensionFactory
     * @param GetSalesChannelForOrder $getSalesChannelForOrder
     * @param SourceDeductionServiceInterface $sourceDeductionService
     * @param GetSourceItemsBySkuInterface $getSourceItemsBySku
     * @param SalesEventInterfaceFactory $salesEventFactory
     * @param ItemToDeductFactory $itemToDeductFactory
     */
    public function __construct(
        GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        GetProductTypesBySkusInterface $getProductTypesBySkus,
        OrderRepositoryInterface $orderRepository,
        DefaultSourceProviderInterface $defaultSourceProvider,
        GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStockOrderedByPriority,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        SourceDeductionRequestFactory $sourceDeductionRequestFactory,
        SalesEventExtensionFactory $salesEventExtensionFactory,
        GetSalesChannelForOrder $getSalesChannelForOrder,
        SourceDeductionServiceInterface $sourceDeductionService,
        GetSourceItemsBySkuInterface $getSourceItemsBySku,
        SalesEventInterfaceFactory $salesEventFactory,
        ItemToDeductFactory $itemToDeductFactory
    ) {
        $this->getSkuFromOrderItem = $getSkuFromOrderItem;


        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
        $this->getProductTypesBySkus = $getProductTypesBySkus;
        $this->orderRepository = $orderRepository;
        $this->defaultSourceProvider = $defaultSourceProvider;
        $this->getSourcesAssignedToStockOrderedByPriority = $getSourcesAssignedToStockOrderedByPriority;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;

        $this->sourceDeductionRequestFactory = $sourceDeductionRequestFactory;
        $this->salesEventExtensionFactory = $salesEventExtensionFactory;
        $this->getSalesChannelForOrder = $getSalesChannelForOrder;
        $this->sourceDeductionService = $sourceDeductionService;
        $this->getSourceItemsBySku = $getSourceItemsBySku;
        $this->salesEventFactory = $salesEventFactory;
        $this->itemToDeductFactory = $itemToDeductFactory;
    }


    public function execute(Observer $observer)
    {
        /* @var $creditMemo Creditmemo */
        $creditMemo = $observer->getEvent()->getCreditmemo();
        $order = $this->orderRepository->get($creditMemo->getOrderId());
        $websiteId = (int)$order->getStore()->getWebsiteId();
        $salesChannel = $this->getSalesChannelForOrder->execute($order);

        $items = [];
        foreach ($creditMemo->getItems() as $item) {
            $orderItem = $item->getOrderItem();
            $itemSku = $this->getSkuFromOrderItem->execute($orderItem);

            if ($this->isValidItem($itemSku, $orderItem->getProductType()) && $item->getBackToStock()) {
                $qty = $item->getQty();
                $stockId = (int)$this->stockByWebsiteIdResolver->execute($websiteId)->getStockId();
                $sourceCode = $this->getSourceCodeWithHighestPriorityBySku($itemSku, $stockId);
                $items[$sourceCode][] = $this->itemToDeductFactory->create([
                    'sku' => $itemSku,
                    'qty' => -$qty
                ]);
            }
        }

        /** @var SalesEventExtensionInterface */
        $salesEventExtension = $this->salesEventExtensionFactory->create([
            'data' => ['objectIncrementId' => (string)$order->getIncrementId()]
        ]);
        /** @var SalesEventInterface $salesEvent */
        $salesEvent = $this->salesEventFactory->create([
            'type' => SalesEventInterface::EVENT_CREDITMEMO_CREATED,
            'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
            'objectId' => (string)$order->getEntityId()
        ]);
        $salesEvent->setExtensionAttributes($salesEventExtension);

        foreach ($items as $sourceCode => $sources) {
            $sourceDeductionRequest = $this->sourceDeductionRequestFactory->create([
                'sourceCode' => $sourceCode,
                'items' => $sources,
                'salesChannel' => $salesChannel,
                'salesEvent' => $salesEvent
            ]);
            $this->sourceDeductionService->execute($sourceDeductionRequest);
        }
    }

    /**
     * Verify is item valid for return qty to stock.
     *
     * @param string $sku
     * @param string|null $typeId
     *
     * @return bool
     */
    private function isValidItem(string $sku, ?string $typeId): bool
    {
        // https://github.com/magento-engcom/msi/issues/1761
        // If product type located in table sales_order_item is "grouped" replace it with "simple"
        if ($typeId === 'grouped') {
            $typeId = 'simple';
        }

        $productType = $typeId ?: $this->getProductTypesBySkus->execute(
            [$sku]
        )[$sku];

        return $this->isSourceItemManagementAllowedForProductType->execute($productType);
    }

    /**
     * Returns source code with the highest priority by sku
     *
     * @param string $sku
     * @param int $stockId
     *
     * @return string
     */
    private function getSourceCodeWithHighestPriorityBySku(string $sku, int $stockId): string
    {
        $sourceCode = $this->defaultSourceProvider->getCode();
        try {
            $availableSourcesForProduct = $this->getSourceItemsBySku->execute($sku);
            $assignedSourcesToStock = $this->getSourcesAssignedToStockOrderedByPriority->execute($stockId);
            foreach ($assignedSourcesToStock as $assignedSource) {
                foreach ($availableSourcesForProduct as $availableSource) {
                    if ($assignedSource->getSourceCode() == $availableSource->getSourceCode()) {
                        $sourceCode = $assignedSource->getSourceCode();
                        break 2;
                    }
                }
            }
        } catch (LocalizedException $e) {
            //Use Default Source if the source can't be resolved
            return $sourceCode;
        }

        return $sourceCode;
    }
}