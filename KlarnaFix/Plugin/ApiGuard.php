<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Psr\Log\LoggerInterface;

class ApiGuard
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private LoggerInterface $logger
    ) {}

    /**
     * Works for BOTH:
     *  - Magento\Checkout\Model\PaymentInformationManagement::savePaymentInformationAndPlaceOrder
     *  - Magento\Checkout\Model\GuestPaymentInformationManagement::savePaymentInformationAndPlaceOrder
     *
     * We keep $subject untyped and use ...$args to support both method signatures.
     */
    public function aroundSavePaymentInformationAndPlaceOrder(
        $subject,
        \Closure $proceed,
        ...$args
    ) {
        // Find the PaymentInterface argument among $args (works for both signatures)
        $payment = null;
        foreach ($args as $a) {
            if ($a instanceof PaymentInterface) {
                $payment = $a;
                break;
            }
        }

        if ($payment) {
            $method = (string)$payment->getMethod();

            // Only affect Klarna methods
            if (str_starts_with($method, 'klarna_')) {
                $lastOrderId = (int)$this->checkoutSession->getLastOrderId();
                if ($lastOrderId > 0) {
                    $this->logger->info('[KlarnaFix] ApiGuard short-circuit savePaymentInformationAndPlaceOrder', [
                        'method'    => $method,
                        'returning' => $lastOrderId,
                    ]);
                    return $lastOrderId;
                }
            }
        }

        // Normal flow
        return $proceed(...$args);
    }
}
