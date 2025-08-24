<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\Data\PaymentInterface as PaymentData;
use Psr\Log\LoggerInterface;

class ApiGuard
{
    private const SUCCESS_TS = 'gbs_success_t';
    private const FRESH_TTL  = 15; // seconds to treat calls as the same "success-leg"

    public function __construct(
        private CheckoutSession $checkoutSession,
        private LoggerInterface $logger
    ) {}

    private function extractPayment(array $args): ?PaymentData
    {
        foreach ($args as $a) {
            if ($a instanceof PaymentData) {
                return $a;
            }
        }
        return null;
    }

    private function isKlarna(?PaymentData $p): bool
    {
        $m = (string)($p?->getMethod() ?? '');
        return $m !== '' && (str_starts_with($m, 'klarna_') || str_starts_with($m, 'kco_'));
    }

    private function isFresh(): bool
    {
        $ts = (int)($this->checkoutSession->getData(self::SUCCESS_TS) ?: 0);
        return $ts && (time() - $ts) < self::FRESH_TTL;
    }

    /** savePaymentInformation → bool */
    public function aroundSavePaymentInformation($subject, \Closure $proceed, ...$args)
    {
        $payment = $this->extractPayment($args);
        if (!$this->isKlarna($payment)) {
            return $proceed(...$args);
        }

        $orderId = (int)($this->checkoutSession->getLastOrderId() ?: 0);
        if ($orderId > 0 && $this->isFresh()) {
            $this->logger->info('[KlarnaFix] ApiGuard: skip savePaymentInformation (fresh success leg)', [
                'order_id' => $orderId
            ]);
            return true; // avoid the noisy 404
        }

        return $proceed(...$args);
    }

    /** savePaymentInformationAndPlaceOrder → int (order id) */
    public function aroundSavePaymentInformationAndPlaceOrder($subject, \Closure $proceed, ...$args)
    {
        $payment = $this->extractPayment($args);
        if (!$this->isKlarna($payment)) {
            return $proceed(...$args);
        }

        $orderId = (int)($this->checkoutSession->getLastOrderId() ?: 0);
        if ($orderId > 0 && $this->isFresh()) {
            $this->logger->info('[KlarnaFix] ApiGuard: short-circuit placeOrder (fresh success leg)', [
                'order_id' => $orderId
            ]);
            return $orderId;
        }

        return $proceed(...$args);
    }
}
