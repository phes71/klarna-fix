<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Api\PaymentInformationManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Psr\Log\LoggerInterface;

class GuardCustomerSavePayment
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private LoggerInterface $logger
    ) {}

    /**
     * Swallow 404 for savePaymentInformation during Klarna return (logged-in).
     * Return true so the frontend can continue to success.
     */
    public function aroundSavePaymentInformation(
        PaymentInformationManagementInterface $subject,
        \Closure $proceed,
        int|string $cartId,
        PaymentInterface $paymentMethod,
        AddressInterface $billingAddress = null
    ) {
        try {
            return $proceed($cartId, $paymentMethod, $billingAddress);
        } catch (NoSuchEntityException $e) {
            $lastOrderId = (int)$this->checkoutSession->getLastOrderId();
            $method = (string)$paymentMethod->getMethod();
            $isKlarna = $method !== '' && (
                function_exists('str_starts_with')
                    ? str_starts_with($method, 'klarna_')
                    : strpos($method, 'klarna_') === 0
            );

            if ($isKlarna && $lastOrderId > 0) {
                $this->logger->info('[KlarnaFix] GuardCustomerSavePayment swallowed 404', [
                    'cartId'   => $cartId,
                    'method'   => $method,
                    'order_id' => $lastOrderId,
                    'msg'      => $e->getMessage(),
                ]);
                return true; // same as core on success
            }
            throw $e;
        }
    }
}
