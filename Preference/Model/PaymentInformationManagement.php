<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Local\Test\Preference\Model;

use Magento\Checkout\Api\Exception\PaymentProcessingRateLimitExceededException;
use Magento\Checkout\Api\GuestShippingInformationManagementInterface;
use Magento\Checkout\Api\PaymentProcessingRateLimiterInterface;
use Magento\Checkout\Api\PaymentSavingRateLimiterInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\AddressComparatorInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Quote\Api\Data\AddressInterfaceFactory;


/**
 * Payment information management service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PaymentInformationManagement implements \Magento\Checkout\Api\PaymentInformationManagementInterface
{
    const MAX_QTY_IN_ORDER = 4;
    /**
     * @var \Magento\Quote\Api\BillingAddressManagementInterface
     * @deprecated 100.1.0 This call was substituted to eliminate extra quote::save call
     * @see not in use anymore
     */
    protected $billingAddressManagement;

    /**
     * @var \Magento\Quote\Api\PaymentMethodManagementInterface
     */
    protected $paymentMethodManagement;

    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var PaymentDetailsFactory
     */
    protected $paymentDetailsFactory;

    /**
     * @var \Magento\Quote\Api\CartTotalRepositoryInterface
     */
    protected $cartTotalsRepository;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var PaymentProcessingRateLimiterInterface
     */
    private $paymentRateLimiter;

    /**
     * @var PaymentSavingRateLimiterInterface
     */
    private $saveRateLimiter;

    /**
     * @var bool
     */
    private $saveRateLimiterDisabled = false;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var AddressComparatorInterface
     */
    private $addressComparator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    private ProductRepositoryInterface $productRepository;
    private GuestShippingInformationManagementInterface $guestShippingInformationManagement;
    private ShippingInformationInterfaceFactory $shippingInformationInterfaceFactory;
    private AddressInterfaceFactory $addressInterfaceFactory;

    /**
     * @param \Magento\Quote\Api\BillingAddressManagementInterface $billingAddressManagement
     * @param \Magento\Quote\Api\PaymentMethodManagementInterface $paymentMethodManagement
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param PaymentDetailsFactory $paymentDetailsFactory
     * @param \Magento\Quote\Api\CartTotalRepositoryInterface $cartTotalsRepository
     * @param PaymentProcessingRateLimiterInterface|null $paymentRateLimiter
     * @param PaymentSavingRateLimiterInterface|null $saveRateLimiter
     * @param CartRepositoryInterface|null $cartRepository
     * @param AddressRepositoryInterface|null $addressRepository
     * @param AddressComparatorInterface|null $addressComparator
     * @param LoggerInterface|null $logger
     * @codeCoverageIgnore
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Quote\Api\BillingAddressManagementInterface $billingAddressManagement,
        \Magento\Quote\Api\PaymentMethodManagementInterface $paymentMethodManagement,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Checkout\Model\PaymentDetailsFactory $paymentDetailsFactory,
        \Magento\Quote\Api\CartTotalRepositoryInterface $cartTotalsRepository,
        ProductFactory $productFactory,
        ProductRepositoryInterface $productRepository,
        GuestShippingInformationManagementInterface $guestShippingInformationManagement,
        ShippingInformationInterfaceFactory $shippingInformationInterfaceFactory,
        AddressInterfaceFactory $addressInterfaceFactory,
        ?PaymentProcessingRateLimiterInterface $paymentRateLimiter = null,
        ?PaymentSavingRateLimiterInterface $saveRateLimiter = null,
        ?CartRepositoryInterface $cartRepository = null,
        ?AddressRepositoryInterface $addressRepository = null,
        ?AddressComparatorInterface $addressComparator = null,
        ?LoggerInterface $logger = null
    ) {
        $this->billingAddressManagement = $billingAddressManagement;
        $this->paymentMethodManagement = $paymentMethodManagement;
        $this->cartManagement = $cartManagement;
        $this->paymentDetailsFactory = $paymentDetailsFactory;
        $this->cartTotalsRepository = $cartTotalsRepository;
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->shippingInformationInterfaceFactory = $shippingInformationInterfaceFactory;
        $this->guestShippingInformationManagement = $guestShippingInformationManagement;
        $this->addressInterfaceFactory = $addressInterfaceFactory;
        $this->paymentRateLimiter = $paymentRateLimiter
            ?? ObjectManager::getInstance()->get(PaymentProcessingRateLimiterInterface::class);
        $this->saveRateLimiter = $saveRateLimiter
            ?? ObjectManager::getInstance()->get(PaymentSavingRateLimiterInterface::class);
        $this->cartRepository = $cartRepository
            ?? ObjectManager::getInstance()->get(CartRepositoryInterface::class);
        $this->addressRepository = $addressRepository
            ?? ObjectManager::getInstance()->get(AddressRepositoryInterface::class);
        $this->addressComparator = $addressComparator
            ?? ObjectManager::getInstance()->get(AddressComparatorInterface::class);
        $this->logger = $logger ?? ObjectManager::getInstance()->get(LoggerInterface::class);
    }

    /**
     * @inheritdoc
     */
    public function savePaymentInformationAndPlaceOrder(
        $cartId,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/savePaymentInformationAndPlaceOrder.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $originQuote = $this->cartRepository->getActive($cartId);
        if ($originQuote->getItemsQty() > 5) {
            $orderId = '';
            $groupIndex = 0;
            $groupLeftQty = self::MAX_QTY_IN_ORDER;
            $splitItems = [];
            foreach ($originQuote->getAllVisibleItems() as $item) {
                $itemParams = $item->getBuyRequest()->convertToArray();
                if (isset($itemParams['original_qty'])) {
                    unset($itemParams['original_qty']);
                }
                if (isset($itemParams['uenc'])) {
                    unset($itemParams['uenc']);
                }
                $logger->info(print_r($itemParams, true));
                $itemQty = $item->getQty();
                /**
                 * If item has quantity smaller than the left qty, add item into the group, update left qty to new value
                 * If item has quantity equal the left qty, add item into group, reset left qty to max value and increase group Index
                 * It item has quantity greater than left qty, add item with left qty into the group, move to the next group,
                 * continue adding item into the new groups until all qty are added
                 */
                if ($groupLeftQty >= $itemQty) {
                    $splitItems[$groupIndex][] = $itemParams;
                    $groupLeftQty = $groupLeftQty - $itemQty;
                    if ($groupLeftQty == 0) {
                        $groupLeftQty = self::MAX_QTY_IN_ORDER;
                        $groupIndex++;
                    }
                } else {
                    $allItemAdded = false;
                    while (!$allItemAdded) {
                        if ($itemQty < $groupLeftQty) {
                            $groupLeftQty = $groupLeftQty - $itemQty;
                            $itemParams['qty'] = $itemQty;
                            $splitItems[$groupIndex][] = $itemParams;
                            $allItemAdded = true;
                        } else {
                            $itemParams['qty'] = $groupLeftQty;
                            $itemQty = $itemQty - $groupLeftQty;
                            $groupLeftQty = self::MAX_QTY_IN_ORDER;
                            $splitItems[$groupIndex][] = $itemParams;
                            $groupIndex++;
                            if ($itemQty == 0) {
                                $allItemAdded = true;
                            }
                        }
                    }
                }
            }
            $shippingAddress = $originQuote->getShippingAddress();

            $logger->info(print_r($splitItems, true));
            foreach ($splitItems as $itemParams) {
                $logger->info('itemParams=============');
                $logger->info(print_r($itemParams, true));
                try {
                    $newCartId = $this->createNewQuoteFromCurrentQuote($itemParams, $originQuote, $shippingAddress);
                    $orderId = $this->processCreatingOrder($newCartId, $paymentMethod, $billingAddress);
                } catch (CouldNotSaveException $e) {
                    $logger->info('CouldNotSaveException savePaymentInformationAndPlaceOrder exception');
                    $logger->info(print_r(debug_backtrace(2),true));
                } catch (NoSuchEntityException $e) {
                    $logger->info('NoSuchEntityException savePaymentInformationAndPlaceOrder exception');
                    $logger->info(print_r(debug_backtrace(2),true));
                } catch (LocalizedException $e) {
                    $logger->info('LocalizedException savePaymentInformationAndPlaceOrder exception');
                    $logger->info(print_r(debug_backtrace(2),true));
                }
            }
            foreach ($splitItems as $itemParams) {
                $newCartId = $this->createNewQuoteFromCurrentQuote($itemParams, $originQuote, $shippingAddress);
                $orderId = ',' . $this->processCreatingOrder($newCartId, $paymentMethod, $billingAddress);
            }
            return $orderId;
        }

        return $this->processCreatingOrder($cartId, $paymentMethod, $billingAddress);
    }

    /**
     * @param array $groupItems
     * @param \Magento\Quote\Model\Quote $originQuote
     * @param \Magento\Quote\Model\Quote\Address $shippingAddress
     * @return mixed
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    private function createNewQuoteFromCurrentQuote($itemParams, $originQuote, $shippingAddress)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/createNewQuoteFromCurrentQuote.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $customerId = $originQuote->getCustomerId();
        $newQuoteId = $this->cartManagement->createEmptyCartForCustomer($customerId);
        /* @var $newQuote \Magento\Quote\Model\Quote */
        $newQuote = $this->cartRepository->getActive($newQuoteId);
        $firstTime = true;
        foreach ($itemParams as $itemBuyRequest) {
            $logger->info(print_r($itemBuyRequest, true));
            if (($newQuote->getId() == $originQuote->getId()) && $firstTime) {
                $firstTime = false;
                $newQuote->removeAllItems();
                $this->cartRepository->save($newQuote);
            }
            $product = $this->productRepository->getById($itemBuyRequest['product']);
            $itemBuyRequestObject = new \Magento\Framework\DataObject($itemBuyRequest);
            $newQuote->addProduct($product, $itemBuyRequestObject);
            $this->cartRepository->save($newQuote);
        }
        $shippingAddressData = $shippingAddress->getData();
        $shippingAddressData['address_id'] = null;
        $shippingAddressData['entity_id'] = null;
        $shippingAddressObject = $this->addressInterfaceFactory->create(['data' => [
            'email' => $shippingAddressData['email'],
            'country_id' => $shippingAddressData['country_id'],
            'region_id' => $shippingAddressData['region_id'],
            'street' => $shippingAddressData['street'],
            'company' => $shippingAddressData['company'],
            'telephone' => $shippingAddressData['telephone'],
            'postcode' => $shippingAddressData['postcode'],
            'firstname' => $shippingAddressData['firstname'],
            'lastname' => $shippingAddressData['lastname'],
            'city' => $shippingAddressData['city']
        ]]);

        $addressInformation = $this->shippingInformationInterfaceFactory->create(
            [
                'data' => [
                    ShippingInformationInterface::SHIPPING_ADDRESS => $shippingAddressObject,
                    ShippingInformationInterface::SHIPPING_CARRIER_CODE => 'flatrate',
                    ShippingInformationInterface::SHIPPING_METHOD_CODE => 'flatrate'
                ],
            ]
        );
        try {
            $this->guestShippingInformationManagement->saveAddressInformation($newQuoteId, $addressInformation);
        } catch (\Exception $e) {
            $logger->info("Error on saving shipping information!!!!!!");
            $logger->info(print_r(debug_backtrace(2),true));
        }

        return $newQuote->getId();
    }

    /**
     * @param $cartId
     * @param $paymentMethod
     * @param $billingAddress
     * @return int
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws PaymentProcessingRateLimitExceededException
     */
    private function processCreatingOrder($cartId, $paymentMethod, $billingAddress)
    {
        $this->paymentRateLimiter->limit();
        try {
            //Have to do this hack because of plugins for savePaymentInformation()
            $this->saveRateLimiterDisabled = true;
            $this->savePaymentInformation($cartId, $paymentMethod, $billingAddress);
        } finally {
            $this->saveRateLimiterDisabled = false;
        }
        try {
            $orderId = $this->cartManagement->placeOrder($cartId);
        } catch (LocalizedException $e) {
            $this->logger->critical(
                'Placing an order with quote_id ' . $cartId . ' is failed: ' . $e->getMessage()
            );
            throw new CouldNotSaveException(
                __($e->getMessage()),
                $e
            );
        } catch (\Exception $e) {
            $this->logger->critical($e);
            throw new CouldNotSaveException(
                __('A server error stopped your order from being placed. Please try to place your order again.'),
                $e
            );
        }

        return $orderId;
    }



    /**
     * @param $cartId
     * @param $paymentMethod
     * @param $billingAddress
     * @return int
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws PaymentProcessingRateLimitExceededException
     */
    private function processCreatingOrder2($cartId, $paymentMethod, $billingAddress)
    {
        if (!$this->saveRateLimiterDisabled) {
            try {
                $this->saveRateLimiter->limit();
            } catch (PaymentProcessingRateLimitExceededException $ex) {
                //Limit reached
                return false;
            }
        }

        if ($billingAddress) {
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $this->cartRepository->getActive($cartId);
            $customerId = $quote->getBillingAddress()
                ->getCustomerId();
            if (!$billingAddress->getCustomerId() && $customerId) {
                //It's necessary to verify the price rules with the customer data
                $billingAddress->setCustomerId($customerId);
            }
            $quote->removeAddress($quote->getBillingAddress()->getId());
            $quote->setBillingAddress($billingAddress);
            $quote->setDataChanges(true);
            if ($quote->getShippingAddress()) {
                $this->processShippingAddress($quote);
            }
        }
        $this->paymentMethodManagement->set($cartId, $paymentMethod);
        return true;
    }

    /**
     * @inheritdoc
     *
     * @throws LocalizedException
     */
    public function savePaymentInformation(
        $cartId,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/savePaymentInformation.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info(print_r(debug_backtrace(2),true));
        $originQuote = $this->cartRepository->getActive($cartId);
        if ($originQuote->getItemsQty() > 5) {
            $orderId = '';
            $groupIndex = 0;
            $groupLeftQty = self::MAX_QTY_IN_ORDER;
            $splitItems = [];
            foreach ($originQuote->getAllVisibleItems() as $item) {
                $itemParams = $item->getBuyRequest();
                $itemQty = $item->getQty();
                /**
                 * If item has quantity smaller than the left qty, add item into the group, update left qty to new value
                 * If item has quantity equal the left qty, add item into group, reset left qty to max value and increase group Index
                 * It item has quantity greater than left qty, add item with left qty into the group, move to the next group,
                 * continue adding item into the new groups until all qty are added
                 */
                if ($groupLeftQty >= $itemQty) {
                    $splitItems[$groupIndex][] = $itemParams;
                    $groupLeftQty = $groupLeftQty - $itemQty;
                    if ($groupLeftQty == 0) {
                        $groupLeftQty = 4;
                        $groupIndex++;
                    }
                } else {
                    $allItemAdded = false;
                    while (!$allItemAdded) {
                        if ($itemQty < $groupLeftQty) {
                            $groupLeftQty = $groupLeftQty - $itemQty;
                            $itemParams->setData('qty', $itemQty);
                            $allItemAdded = true;
                        } else {
                            $itemParams->setData('qty', $groupLeftQty);
                            $itemQty = $itemQty - $groupLeftQty;
                            $groupLeftQty = 4;
                        }
                        $splitItems[$groupIndex][] = $itemParams;
                        $groupIndex++;
                    }
                }
            }
            $shippingAddress = $originQuote->getShippingAddress();
            foreach ($splitItems as $itemParams) {
                $newCartId = $this->createNewQuoteFromCurrentQuote($itemParams, $originQuote, $shippingAddress);
                $orderId = ',' . $this->processCreatingOrder2($newCartId, $paymentMethod, $billingAddress);
            }
            return $orderId;
        }

        return $this->processCreatingOrder2($cartId, $paymentMethod, $billingAddress);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentInformation($cartId)
    {
        /** @var \Magento\Checkout\Api\Data\PaymentDetailsInterface $paymentDetails */
        $paymentDetails = $this->paymentDetailsFactory->create();
        $paymentDetails->setPaymentMethods($this->paymentMethodManagement->getList($cartId));
        $paymentDetails->setTotals($this->cartTotalsRepository->get($cartId));
        return $paymentDetails;
    }

    /**
     * Processes shipping address.
     *
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    private function processShippingAddress(Quote $quote): void
    {
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();
        if ($shippingAddress->getShippingMethod()) {
            $shippingRate = $shippingAddress->getShippingRateByCode($shippingAddress->getShippingMethod());
            if ($shippingRate) {
                $shippingAddress->setLimitCarrier($shippingRate->getCarrier());
            }
        }
        if ($this->addressComparator->isEqual($shippingAddress, $billingAddress)) {
            $shippingAddress->setSameAsBilling(1);
        }
        // Save new address in the customer address book and set it id for billing and shipping quote addresses.
        if ($shippingAddress->getSameAsBilling() && $shippingAddress->getSaveInAddressBook()) {
            $shippingAddressData = $shippingAddress->exportCustomerAddress();
            $customer = $quote->getCustomer();
            $hasDefaultBilling = (bool)$customer->getDefaultBilling();
            $hasDefaultShipping = (bool)$customer->getDefaultShipping();
            if (!$hasDefaultShipping) {
                //Make provided address as default shipping address
                $shippingAddressData->setIsDefaultShipping(true);
                if (!$hasDefaultBilling && !$billingAddress->getSaveInAddressBook()) {
                    $shippingAddressData->setIsDefaultBilling(true);
                }
            }
            $shippingAddressData->setCustomerId($quote->getCustomerId());
            $this->addressRepository->save($shippingAddressData);
            $quote->addCustomerAddress($shippingAddressData);
            $shippingAddress->setCustomerAddressData($shippingAddressData);
            $shippingAddress->setCustomerAddressId($shippingAddressData->getId());
            $billingAddress->setCustomerAddressId($shippingAddressData->getId());
        }
    }
}
