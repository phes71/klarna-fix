<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

class SuccessAccessGuard
{
    public function __construct(
        private CheckoutSession $session,
        private ResultFactory $resultFactory,
        private LoggerInterface $logger
    ) {}

    private function isKlarna(?string $code): bool
    {
        if (!$code) return false;
        $code = strtolower($code);
        return $code === 'klarna_pay_now'
            || $code === 'klarna_pay_later'
            || $code === 'klarna_slice_it'
            || str_starts_with($code, 'klarna');
    }

    public function aroundExecute(Success $subject, \Closure $proceed)
    {
        $order = $this->session->getLastRealOrder();
        $method = $order && $order->getPayment() ? (string)$order->getPayment()->getMethod() : '';

        // Non-Klarna: let Magento (and gateways like Clearpay/Braintree) do their thing
        if (!$this->isKlarna($method)) {
            $this->logger->info('[KlarnaFix] SuccessAccessGuard: bypass (non-Klarna)');
            return $proceed();
        }

        // Klarna only: require real success context
        $has = (int)$this->session->getLastOrderId() > 0
            || (string)$this->session->getLastRealOrderId() !== '';

        if (!$has) {
            $this->logger->info('[KlarnaFix] SuccessAccessGuard: deny (no context)');
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                ->setPath('checkout/cart');
        }

        // Mark for cleanup AFTER render (Klarna only)
        $this->session->setData('gbs_success_pending_cleanup', 1);

        return $proceed();
    }
}
