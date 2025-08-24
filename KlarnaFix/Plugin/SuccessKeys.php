<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Registry;
use GerrardSBS\KlarnaFix\Model\KeysSetter;
use Psr\Log\LoggerInterface;

class SuccessKeys
{
    public function __construct(
        private KeysSetter $keysSetter,
        private CheckoutSession $checkoutSession,
        private OrderRepositoryInterface $orderRepository,
        private Registry $registry,
        private LoggerInterface $logger
    ) {}

    public function beforeExecute(Success $subject): void
    {
        // Ensure last* IDs are present in session
        $this->keysSetter->ensure();

        try {
            $orderId = (int)($this->checkoutSession->getLastOrderId() ?: 0);
            if ($orderId > 0) {
                $order = $this->orderRepository->get($orderId);

                // Reset any stale registry values before binding
                foreach (['current_order','order','sales_order'] as $key) {
                    if ($this->registry->registry($key)) {
                        $this->registry->unregister($key);
                    }
                }
                $this->registry->register('current_order', $order);
                $this->registry->register('order', $order);
                $this->registry->register('sales_order', $order);

                // Make sure increment/quote ids exist in session for blocks/layout that rely on them
                if (!$this->checkoutSession->getLastRealOrderId()) {
                    $this->checkoutSession->setLastRealOrderId((string)$order->getIncrementId());
                }
                if (!$this->checkoutSession->getLastQuoteId()) {
                    $qid = (int)$order->getQuoteId();
                    if ($qid) {
                        $this->checkoutSession->setLastQuoteId($qid);
                        $this->checkoutSession->setLastSuccessQuoteId($qid);
                    }
                }

                $this->logger->info('[KlarnaFix] SuccessKeys: bound order', [
                    'entity_id' => $order->getEntityId(),
                    'increment' => $order->getIncrementId(),
                    'quote_id'  => $order->getQuoteId(),
                ]);
            } else {
                // No session order → core Success controller should redirect to cart
                $this->logger->warning('[KlarnaFix] SuccessKeys: no lastOrderId in session on success');
            }
        } catch (\Throwable $e) {
            $this->logger->critical('[KlarnaFix] SuccessKeys registry bind failed: ' . $e->getMessage());
        }

        // Prime the “fresh success” timestamp used by CookieGuard
        $this->checkoutSession->setData('gbs_success_t', time());
        $this->logger->info('[KlarnaFix] SuccessKeys primed success timestamp');
    }
}
