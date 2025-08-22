<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Psr\Log\LoggerInterface;

class GuardGuestSavePayment
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private LoggerInterface $logger
    ) {}

    /**
     * Swallow 404 for savePaymentInformation during Klarna return after the order is placed.
     * Return true (same as Magento on success) so the frontend proceeds.
     */
    public function aroundSavePaymentInformation(
        GuestPaymentInformationManagementInterface $subject,
        \Closure $proceed,
        string $cartId,
        string $email,
        PaymentInterface $paymentMethod,
        AddressInterface $billingAddress = null
    ) {
        try {
            return $proceed($cartId, $email, $paymentMethod, $billingAddress);
        } catch (NoSuchEntityException $e) {
            $lastOrderId = (int)$this->checkoutSession->getLastOrderId();
            $method = (string)$paymentMethod->getMethod();
            $isKlarna = ($method !== '' && (function_exists('str_starts_with')
                ? str_starts_with($method, 'klarna_')
                : strpos($method, 'klarna_') === 0));

            if ($isKlarna && $lastOrderId > 0) {
                $this->logger->info('[KlarnaFix] GuardGuestSavePayment swallowed 404', [
                    'cartId'   => $cartId,
                    'email'    => $email,
                    'method'   => $method,
                    'order_id' => $lastOrderId,
                    'msg'      => $e->getMessage(),
                ]);
                return true; // success
            }
            throw $e;
        }
    }
}
