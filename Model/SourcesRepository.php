<?php

namespace Ampersand\DisableStockReservation\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\CouldNotSaveException;
use Ampersand\DisableStockReservation\Model\SourcesFactory;
use Ampersand\DisableStockReservation\Model\ResourceModel\Sources;
use Ampersand\DisableStockReservation\Model\Sources as SourceModel;
use Ampersand\DisableStockReservation\Model\ResourceModel\Sources\CollectionFactory;
use Ampersand\DisableStockReservation\Api\Data\SourcesInterface;

/**
 * Class SourcesRepository
 * @package Ampersand\DisableStockReservation\Model
 */
class SourcesRepository
{
    /**
     * @var SourcesFactory
     */
    protected $sourcesFactory;

    /**
     * @var Sources
     */
    protected $sourcesResourceModel;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * SourcesRepository constructor.
     * @param SourcesFactory $sourcesFactory
     * @param Sources $sourcesResourceModel
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        SourcesFactory $sourcesFactory,
        Sources $sourcesResourceModel,
        CollectionFactory $collectionFactory
    ) {
        $this->sourcesFactory = $sourcesFactory;
        $this->sourcesResourceModel = $sourcesResourceModel;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @param string $orderId
     *
     * @return SourceModel
     * @throws \Exception
     */
    public function getByOrderId(string $orderId): SourceModel
    {
        /** @var SourceModel $sourcesModel */
        $sourcesModel = $this->sourcesFactory->create();
        $this->sourcesResourceModel->load(
            $sourcesModel,
            $orderId,
            'order_id'
        );

        if (!$sourcesModel->getId()) {
            throw new NoSuchEntityException(__('Source model with the order ID "%1', $orderId));
        }

        return $sourcesModel;
    }

    /**
     * @param array $ordersIds
     * @return array
     */
    public function getOrderListSources(array $ordersIds): array
    {
        $ordersSourcesItems = $this->collectionFactory->create()
            ->addFieldToFilter('order_id', ['in' => $ordersIds])
            ->getItems();

        return $ordersSourcesItems;
    }

    /**
     * @param SourcesInterface $model
     *
     * @return SourcesInterface
     * @throws CouldNotSaveException
     */
    public function save(SourcesInterface $model): SourcesInterface
    {
        try {
            $this->sourcesResourceModel->save($model);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__($exception->getMessage()));
        }

        return $model;
    }
}
