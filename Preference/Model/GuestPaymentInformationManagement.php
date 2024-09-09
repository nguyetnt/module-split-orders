<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Local\Test\Preference\Model;

use Magento\Checkout\Api\Exception\PaymentProcessingRateLimitExceededException;
use Magento\Checkout\Api\PaymentProcessingRateLimiterInterface;
use Magento\Checkout\Api\PaymentSavingRateLimiterInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface as Logger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\AddressComparatorInterface;
use Magento\Checkout\Api\GuestShippingInformationManagementInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Quote\Api\Data\AddressInterfaceFactory;

/**
 * Guest payment information management model.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class GuestPaymentInformationManagement extends \Magento\Checkout\Model\GuestPaymentInformationManagement
    implements \Magento\Checkout\Api\GuestPaymentInformationManagementInterface
{
    const MAX_QTY_IN_ORDER = 4;

    /**
     * @var \Magento\Quote\Api\GuestBillingAddressManagementInterface
     */
    protected $billingAddressManagement;

    /**
     * @var \Magento\Quote\Api\GuestPaymentMethodManagementInterface
     */
    protected $paymentMethodManagement;

    /**
     * @var \Magento\Quote\Api\GuestCartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var \Magento\Checkout\Api\PaymentInformationManagementInterface
     */
    protected $paymentInformationManagement;

    /**
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;

    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var PaymentProcessingRateLimiterInterface
     */
    private $paymentsRateLimiter;

    /**
     * @var PaymentSavingRateLimiterInterface
     */
    private $savingRateLimiter;

    /**
     * @var bool
     */
    private $saveRateLimitDisabled = false;

    /**
     * @var AddressComparatorInterface
     */
    private $addressComparator;

    private ProductRepositoryInterface $productRepository;
    private GuestShippingInformationManagementInterface $guestShippingInformationManagement;
    private ShippingInformationInterfaceFactory $shippingInformationInterfaceFactory;
    private AddressInterfaceFactory $addressInterfaceFactory;

    /**
     * @param \Magento\Quote\Api\GuestBillingAddressManagementInterface $billingAddressManagement
     * @param \Magento\Quote\Api\GuestPaymentMethodManagementInterface $paymentMethodManagement
     * @param \Magento\Quote\Api\GuestCartManagementInterface $cartManagement
     * @param \Magento\Checkout\Api\PaymentInformationManagementInterface $paymentInformationManagement
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param CartRepositoryInterface $cartRepository
     * @param Logger $logger
     * @param PaymentProcessingRateLimiterInterface|null $paymentsRateLimiter
     * @param PaymentSavingRateLimiterInterface|null $savingRateLimiter
     * @param AddressComparatorInterface|null $addressComparator
     * @codeCoverageIgnore
     */
    public function __construct(
        \Magento\Quote\Api\GuestBillingAddressManagementInterface $billingAddressManagement,
        \Magento\Quote\Api\GuestPaymentMethodManagementInterface $paymentMethodManagement,
        \Magento\Quote\Api\GuestCartManagementInterface $cartManagement,
        \Magento\Checkout\Api\PaymentInformationManagementInterface $paymentInformationManagement,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository,
        Logger $logger,
        ProductRepositoryInterface $productRepository,
        GuestShippingInformationManagementInterface $guestShippingInformationManagement,
        ShippingInformationInterfaceFactory $shippingInformationInterfaceFactory,
        AddressInterfaceFactory $addressInterfaceFactory,
        ?PaymentProcessingRateLimiterInterface $paymentsRateLimiter = null,
        ?PaymentSavingRateLimiterInterface $savingRateLimiter = null,
        ?AddressComparatorInterface $addressComparator = null
    ) {
        $this->billingAddressManagement = $billingAddressManagement;
        $this->paymentMethodManagement = $paymentMethodManagement;
        $this->cartManagement = $cartManagement;
        $this->paymentInformationManagement = $paymentInformationManagement;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->shippingInformationInterfaceFactory = $shippingInformationInterfaceFactory;
        $this->guestShippingInformationManagement = $guestShippingInformationManagement;
        $this->addressInterfaceFactory = $addressInterfaceFactory;
        $this->paymentsRateLimiter = $paymentsRateLimiter
            ?? ObjectManager::getInstance()->get(PaymentProcessingRateLimiterInterface::class);
        $this->savingRateLimiter = $savingRateLimiter
            ?? ObjectManager::getInstance()->get(PaymentSavingRateLimiterInterface::class);
        $this->addressComparator = $addressComparator
            ?? ObjectManager::getInstance()->get(AddressComparatorInterface::class);
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function savePaymentInformationAndPlaceOrder(
        $cartId,
        $email,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/GuestSavePaymentInformationAndPlaceOrder.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        /** @var Quote $quote */
        $originQuote = $this->cartRepository->getActive($quoteIdMask->getQuoteId());
        $logger->info("originQuote");
        $logger->info((string) $originQuote->getId());
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
                    $orderId = $this->processCreatingOrder($newCartId, $email, $paymentMethod, $billingAddress);
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

            return $orderId;
        }

        return $this->processCreatingOrder($cartId, $email, $paymentMethod, $billingAddress);
    }

    /**
     * @param $itemParams
     * @param \Magento\Quote\Model\Quote $originQuote
     * @param $shippingAddress
     * @return mixed
     * @throws CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function createNewQuoteFromCurrentQuote($itemParams, Quote $originQuote, $shippingAddress)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/createNewQuoteFromCurrentQuote.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        $newQuoteId = $this->cartManagement->createEmptyCart();
        $newQuoteIdMask = $this->quoteIdMaskFactory->create()->load($newQuoteId, 'masked_id');

        /* @var $newQuote \Magento\Quote\Model\Quote */
        $newQuote = $this->cartRepository->getActive($newQuoteIdMask->getQuoteId());
        $firstTime = true;
        foreach ($itemParams as $itemBuyRequest) {
            $logger->info(print_r($itemBuyRequest, true));
            if (($newQuote->getId() == $originQuote->getId()) && $firstTime) {
                $firstTime = false;
                $newQuote->removeAllItems();
                $this->cartRepository->save($newQuote);
            }
            try {
                $product = $this->productRepository->getById($itemBuyRequest['product']);
                $itemBuyRequestObject = new \Magento\Framework\DataObject($itemBuyRequest);
                $newQuote->addProduct($product, $itemBuyRequestObject);
            } catch (\Exception $e) {
                $logger->info("Error on adding product to cart!!!!!");
                $logger->info($e->getMessage());
                continue;
            }
        }
        try {
            $this->cartRepository->save($newQuote);
        } catch (\Exception $e) {}
        $shippingAddressData = $shippingAddress->getData();
        $shippingAddressData['address_id'] = null;
        $shippingAddressData['entity_id'] = null;
        $shippingAddressObject = $this->addressInterfaceFactory->create([
            'data' => [
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
            ]
        ]);

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

        return $newQuoteId;
    }

    private function processCreatingOrder($cartId, $email, $paymentMethod, $billingAddress)
    {
        $this->paymentsRateLimiter->limit();
        try {
            //Have to do this hack because of savePaymentInformation() plugins.
            $this->saveRateLimitDisabled = true;
            $this->savePaymentInformation($cartId, $email, $paymentMethod, $billingAddress);
        } finally {
            $this->saveRateLimitDisabled = false;
        }
        try {
            $orderId = $this->cartManagement->placeOrder($cartId);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
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
                __('An error occurred on the server. Please try to place the order again.'),
                $e
            );
        }

        return $orderId;
    }

    /**
     * @inheritdoc
     */
    public function savePaymentInformation(
        $cartId,
        $email,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        if (!$this->saveRateLimitDisabled) {
            try {
                $this->savingRateLimiter->limit();
            } catch (PaymentProcessingRateLimitExceededException $ex) {
                //Limit reached
                return false;
            }
        }

        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        /** @var Quote $quote */
        $quote = $this->cartRepository->getActive($quoteIdMask->getQuoteId());
        $shippingAddress = $quote->getShippingAddress();
        if ($this->addressComparator->isEqual($shippingAddress, $billingAddress)) {
            $shippingAddress->setSameAsBilling(1);
        }
        if ($billingAddress) {
            $billingAddress->setEmail($email);
            $quote->removeAddress($quote->getBillingAddress()->getId());
            $quote->setBillingAddress($billingAddress);
            $quote->setDataChanges(true);
        } else {
            $quote->getBillingAddress()->setEmail($email);
        }
        $this->limitShippingCarrier($quote);

        if (!(float)$quote->getItemsQty()) {
            throw new CouldNotSaveException(__('Some of the products are disabled.'));
        }

        $this->paymentMethodManagement->set($cartId, $paymentMethod);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentInformation($cartId)
    {
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        return $this->paymentInformationManagement->getPaymentInformation($quoteIdMask->getQuoteId());
    }

    /**
     * Limits shipping rates request by carrier from shipping address.
     *
     * @param Quote $quote
     *
     * @return void
     * @see \Magento\Shipping\Model\Shipping::collectRates
     */
    private function limitShippingCarrier(Quote $quote) : void
    {
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress && $shippingAddress->getShippingMethod()) {
            $shippingRate = $shippingAddress->getShippingRateByCode($shippingAddress->getShippingMethod());
            if ($shippingRate) {
                $shippingAddress->setLimitCarrier($shippingRate->getCarrier());
            }
        }
    }
}
