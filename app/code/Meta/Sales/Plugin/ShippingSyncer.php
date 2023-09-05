<?php

declare(strict_types=1);

/**
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Meta\Sales\Plugin;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Meta\BusinessExtension\Helper\GraphAPIAdapter;
use Meta\BusinessExtension\Helper\FBEHelper;
use Meta\BusinessExtension\Model\System\Config as SystemConfig;

class ShippingSyncer
{

    /**
     * @var ShippingDataFactory
     */
    protected ShippingDataFactory $shippingRatesFactory;

    /**
     * @var FBEHelper
     */
    private FBEHelper $fbeHelper;

    /**
     * @var Filesystem
     */
    protected Filesystem $fileSystem;

    /**
     * @var GraphAPIAdapter
     */
    protected $graphApiAdapter;

    /**
     * @var SystemConfig
     */
    protected $systemConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var ShippingData
     */
    private ShippingData $shippingData;

    /**
     * Constructor for Shipping settings update plugin
     *
     * @param Filesystem $fileSystem
     * @param GraphAPIAdapter $graphApiAdapter
     * @param FBEHelper $fbeHelper
     * @param SystemConfig $systemConfig
     * @param StoreManagerInterface $storeManager
     * @param ShippingData $shippingData
     */
    public function __construct(
        FileSystem            $fileSystem,
        GraphAPIAdapter       $graphApiAdapter,
        FBEHelper             $fbeHelper,
        SystemConfig          $systemConfig,
        StoreManagerInterface $storeManager,
        ShippingData          $shippingData,
    ) {
        $this->fileSystem = $fileSystem;
        $this->graphApiAdapter = $graphApiAdapter;
        $this->fbeHelper = $fbeHelper;
        $this->systemConfig = $systemConfig;
        $this->storeManager = $storeManager;
        $this->shippingData = $shippingData;
    }

    /**
     * Syncing shipping profiles to Meta
     *
     * @param string $access_token
     * @param string $partner_integration_id
     * @return void
     * @throws FileSystemException
     */
    public function syncShippingProfiles(string $access_token = null, string $partner_integration_id = null)
    {
        foreach ($this->storeManager->getStores() as $store) {
            try {
                $fileBuilder = new ShippingFileBuilder($this->fileSystem);
                $this->shippingData->setStoreId($store->getId());
                $shippingProfiles = [
                    $this->shippingData->buildShippingProfile(ShippingProfileTypes::TABLE_RATE),
                    $this->shippingData->buildShippingProfile(ShippingProfileTypes::FLAT_RATE),
                    $this->shippingData->buildShippingProfile(ShippingProfileTypes::FREE_SHIPPING),
                ];
                $file_uri = $fileBuilder->createFile($shippingProfiles);
                $access_token = $access_token ?? $this->systemConfig->getAccessToken($store->getId());
                $partner_integration_id = $partner_integration_id ??
                    $this->systemConfig->getCommercePartnerIntegrationId($store->getId());
                $this->graphApiAdapter->setDebugMode($this->systemConfig->isDebugMode($store->getId()))
                    ->setAccessToken($access_token);
                $this->graphApiAdapter->uploadFile($partner_integration_id, $file_uri, "SHIPPING_PROFILES", "CREATE");
            } catch (Exception $e) {
                $this->fbeHelper->logExceptionImmediatelyToMeta($e, [
                    'store_id' => $this->fbeHelper->getStore()->getId(),
                    'event' => 'shipping_profile_sync',
                    'event_type' => 'after_save'
                ]);
            }
        }
    }
}
