<?php

namespace Facebook\BusinessExtension\Helper;

use Exception;
use Facebook\BusinessExtension\Api\Data\FacebookOrderInterfaceFactory;
use Facebook\BusinessExtension\Model\Config\Source\DefaultOrderStatus;
use Facebook\BusinessExtension\Model\System\Config as SystemConfig;
use Facebook\BusinessExtension\Helper\Product\Identifier as ProductIdentifier;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Service\OrderService;
use Magento\Store\Model\StoreManagerInterface;

use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class CommerceHelper extends AbstractHelper
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var QuoteFactory
     */
    private $quote;

    /**
     * @var QuoteManagement
     */
    private $quoteManagement;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var GraphAPIAdapter
     */
    private $graphAPIAdapter;

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * @var OrderExtensionFactory
     */
    protected $orderExtensionFactory;

    /**
     * @var FacebookOrderInterfaceFactory
     */
    protected $facebookOrderFactory;

    /**
     * @var ProductIdentifier
     */
    private $productIdentifier;

    private $orderIds = [];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $ordersPulledTotal = 0;

    /**
     * @var Order[]
     */
    private $ordersCreated = [];

    /**
     * @var array
     */
    private $exceptions = [];

    /**
     * @param Context $context
     * @param ObjectManagerInterface $objectManager
     * @param StoreManagerInterface $storeManager
     * @param QuoteFactory $quote
     * @param QuoteManagement $quoteManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param CustomerFactory $customerFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param GraphAPIAdapter $graphAPIAdapter
     * @param OrderService $orderService
     * @param SystemConfig $systemConfig
     * @param LoggerInterface $logger
     * @param OrderExtensionFactory $orderExtensionFactory
     * @param FacebookOrderInterfaceFactory $facebookOrderFactory
     * @param ProductIdentifier $productIdentifier
     */
    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager,
        QuoteFactory $quote,
        QuoteManagement $quoteManagement,
        OrderRepositoryInterface $orderRepository,
        CustomerFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        GraphAPIAdapter $graphAPIAdapter,
        OrderService $orderService,
        SystemConfig $systemConfig,
        LoggerInterface $logger,
        OrderExtensionFactory $orderExtensionFactory,
        FacebookOrderInterfaceFactory $facebookOrderFactory,
        ProductIdentifier $productIdentifier
    ) {
        $this->storeManager = $storeManager;
        $this->objectManager = $objectManager;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderService = $orderService;
        $this->graphAPIAdapter = $graphAPIAdapter;
        $this->systemConfig = $systemConfig;
        $this->logger = $logger;
        $this->orderExtensionFactory = $orderExtensionFactory;
        $this->facebookOrderFactory = $facebookOrderFactory;
        $this->productIdentifier = $productIdentifier;
        parent::__construct($context);
    }

    /**
     * Get Magento shipping method code. For example: "flatrate_flatrate"
     *
     * @param string $shippingOptionName (possible values: "standard", "expedited", "rush")
     * @param array $shippingAddressData
     * @return mixed
     * @throws LocalizedException
     */
    public function getShippingMethod($shippingOptionName, $shippingAddressData = [])
    {
        $map = $this->systemConfig->getShippingMethodsMap();
        if (stripos($shippingOptionName, 'standard') !== false && array_key_exists('standard', $map)) {
            return $map['standard'];
        }
        if (stripos($shippingOptionName, 'expedited') !== false && array_key_exists('expedited', $map)) {
            return $map['expedited'];
        }
        if (stripos($shippingOptionName, 'rush') !== false && array_key_exists('rush', $map)) {
            return $map['rush'];
        }
        throw new LocalizedException(__('Cannot map shipping method. Make sure mapping is defined in system configuration.'));
    }

    /**
     * @param $item
     * @return OrderItem
     * @throws LocalizedException
     */
    protected function getOrderItem(array $item)
    {
        $product = $this->productIdentifier->getProductByFacebookRetailerId($item['retailer_id']);

        /** @var OrderItem $orderItem */
        $orderItem = $this->objectManager->create(OrderItem::class);
        $orderItem->setProductId($product->getId())
            ->setSku($product->getSku())
            ->setName($product->getName())
            ->setQtyOrdered($item['quantity'])
            ->setBasePrice($item['price_per_unit']['amount'])
            ->setOriginalPrice($item['price_per_unit']['amount'])
            ->setPrice($item['price_per_unit']['amount'])
            ->setTaxAmount($item['tax_details']['estimated_tax']['amount'])
            ->setRowTotal($item['price_per_unit']['amount'] * $item['quantity'])
            ->setProductType($product->getTypeId());
        return $orderItem;
    }

    /**
     * @param $data
     * @return Order
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function createOrder($data)
    {
        $storeId = $this->systemConfig->getStoreId();

        $facebookOrderId = $data['id'];
        /** @var FacebookOrderInterfaceFactory $facebookOrder */
        $facebookOrder = $this->facebookOrderFactory->create();
        $facebookOrder->load($facebookOrderId);

        if ($facebookOrder->getId()) {
            $msg = __(sprintf('Order with Facebook ID %s already exists in Magento', $facebookOrder->getId()));
            throw new LocalizedException($msg);
        }

        $street = isset($data['shipping_address']['street2'])
            ? [$data['shipping_address']['street1'], $data['shipping_address']['street2']]
            : $data['shipping_address']['street1'];

        $objectManager = $this->objectManager;
        $addressData = [
            'region' => $data['shipping_address']['state'],
            //'region_id' => '12',
            'postcode' => $data['shipping_address']['postal_code'],
            'firstname' => explode(' ', $data['shipping_address']['name'])[0],
            'lastname' => explode(' ', $data['shipping_address']['name'])[1],
            'street' => $street,
            'city' => $data['shipping_address']['city'],
            'email' => $data['buyer_details']['email'],
            'telephone' => '0', //is required by magento
            'country_id' => 'US'
        ];
        $channel = ucfirst($data['channel']);
        $shippingOptionName = $data['selected_shipping_option']['name'];
        //var_dump($data); die();

        /** @var Order\Address $billingAddress */
        $billingAddress = $objectManager->create(Order\Address::class, ['data' => $addressData]);
        $billingAddress->setAddressType(Order\Address::TYPE_BILLING);

        /** @var Order\Address $shippingAddress */
        $shippingAddress = clone $billingAddress;
        $shippingAddress->setId(null)->setAddressType(Order\Address::TYPE_SHIPPING)->setSameAsBilling(true);

        // __________________
        /** @var Payment $payment */
        $payment = $this->objectManager->create(Payment::class);
        $payment->setMethod('facebook');

        $orderSubtotalAmount = $data['estimated_payment_details']['subtotal']['items']['amount'];
        $orderTaxAmount = $data['estimated_payment_details']['tax']['amount'];
        $orderTotalAmount = $data['estimated_payment_details']['total_amount']['amount'];
        $customerEmail = $billingAddress->getEmail();

        /** @var Order $order */
        $order = $objectManager->create(Order::class);

        $defaultStatus = $this->systemConfig->getDefaultOrderStatus();
        if ($defaultStatus === DefaultOrderStatus::ORDER_STATUS_PENDING) {
            $order->setState(Order::STATE_NEW)
                ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_NEW));
        } else if ($defaultStatus === DefaultOrderStatus::ORDER_STATUS_PROCESSING) {
            $order->setState(Order::STATE_PROCESSING)
                ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        }

        $order->setCustomerIsGuest(true)
            ->setOrderCurrencyCode('USD')
            ->setBaseCurrencyCode('USD')
            ->setGlobalCurrencyCode('USD')
            ->setStoreCurrencyCode('USD')
            ->setSubtotal($orderSubtotalAmount)
            ->setTaxAmount($orderTaxAmount)
            ->setGrandTotal($orderTotalAmount)
            ->setBaseTotalPaid($orderTotalAmount)
            ->setTotalPaid($orderTotalAmount)
            ->setBaseSubtotal($orderSubtotalAmount)
            ->setBaseGrandTotal($orderTotalAmount)
            ->setCustomerEmail($customerEmail)
            ->setCustomerFirstname(explode(' ', $data['shipping_address']['name'])[0])
            ->setCustomerLastname(explode(' ', $data['shipping_address']['name'])[1])
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress);
        $order->setBaseShippingAmount($data['selected_shipping_option']['price']['amount']);
        $order->setShippingTaxAmount($data['selected_shipping_option']['calculated_tax']['amount']);
        $order->setShippingAmount($data['selected_shipping_option']['price']['amount']);

        $shippingMethod = $this->getShippingMethod($shippingOptionName, $addressData);
        $order->setStoreId($storeId)
            // @todo have to set shipping method like this
            ->setShippingMethod($shippingMethod)
            ->setShippingDescription($shippingOptionName . " / {$shippingMethod}")
            ->setPayment($payment);

        // @todo implement paging and tax for order items
        $items = $this->graphAPIAdapter->getOrderItems($facebookOrderId);
        foreach ($items['data'] as $item) {
            $order->addItem($this->getOrderItem($item));
        }

        $order->addCommentToStatusHistory("Imported order #{$facebookOrderId} from {$channel}.");
        $order->setCanSendNewEmailFlag(false);

        $this->orderRepository->save($order);

        if ($defaultStatus === DefaultOrderStatus::ORDER_STATUS_PROCESSING) {
            // create invoice
            $orderService = $this->objectManager->create(InvoiceManagementInterface::class);
            /** @var Order\Invoice $invoice */
            $invoice = $orderService->prepareInvoice($order);
            $invoice->register();
            $order = $invoice->getOrder();
            $transactionSave = $this->objectManager->create(Transaction::class);
            $transactionSave->addObject($invoice)->addObject($order)->save();
        }

        $extraData = [
            'email_remarketing_option' => $data['buyer_details']['email_remarketing_option'],
        ];

        $facebookOrder = $this->facebookOrderFactory->create();
        $facebookOrder->setFacebookOrderId($facebookOrderId)
            ->setMagentoOrderId($order->getId())
            ->setChannel($channel)
            ->setExtraData($extraData);
        $facebookOrder->save();

        $this->orderIds[$order->getIncrementId()] = $facebookOrderId;
        return $order;
    }

    /**
     * @param false|string $cursorAfter
     */
    public function pullOrders($cursorAfter = false)
    {
        $ordersData = $this->graphAPIAdapter->getOrders($cursorAfter);
        //var_export($ordersData);

        $this->ordersPulledTotal += count($ordersData['data']);

        foreach ($ordersData['data'] as $orderData) {
            try {
                $this->ordersCreated[] = $this->createOrder($orderData);
            } catch (Exception $e) {
                $this->exceptions[] = $e->getMessage();
                $this->logger->critical($e->getMessage());
            }
        }
        if (!empty($this->orderIds)) {
            $this->graphAPIAdapter->acknowledgeOrders($this->orderIds);
            $this->orderIds = [];
        }

        if (isset($ordersData['paging']['next'])) {
            $this->pullOrders($ordersData['paging']['cursors']['after']);
        }
    }

    /**
     * @return array
     */
    public function pullPendingOrders()
    {
        $this->ordersPulledTotal = 0;
        $this->ordersCreated = [];
        $this->exceptions = [];
        $this->pullOrders();
        return [
            'total_orders_pulled' => $this->ordersPulledTotal,
            'total_orders_created' => count($this->ordersCreated),
            'exceptions' => $this->exceptions
        ];
    }

    public function markOrderAsShipped($fbOrderId, $items, $trackingInfo)
    {
        $this->graphAPIAdapter->markOrderAsShipped($fbOrderId, $items, $trackingInfo);
    }

    public function cancelOrder($fbOrderId)
    {
        $this->graphAPIAdapter->cancelOrder($fbOrderId);
    }

    public function refundOrder($fbOrderId, $items, $shippingRefundAmount, $reasonText = null)
    {
        $this->graphAPIAdapter->refundOrder($fbOrderId, $items, $shippingRefundAmount, $reasonText = null);
    }
}