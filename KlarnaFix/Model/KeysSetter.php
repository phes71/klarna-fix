<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class KeysSetter
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private OrderFactory $orderFactory,
        private CartRepositoryInterface $cartRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Ensure all success-related session keys exist.
     * Returns an array with what was set/found.
     */
    public function ensure(): array
    {
        $session = $this->checkoutSession;

        // If already fully set, bail early
        $already =
            (int)$session->getLastOrderId() &&
            (string)$session->getLastRealOrderId() !== '' &&
            (int)$session->getLastQuoteId() &&
            (int)$session->getLastSuccessQuoteId();

        if ($already) {
            return ['ok' => true, 'reason' => 'already_set'];
        }

        $orderId   = (int)($session->getLastOrderId() ?: 0);
        $increment = (string)($session->getLastRealOrderId() ?: '');
        $quoteId   = (int)($session->getLastSuccessQuoteId() ?: ($session->getLastQuoteId() ?: $session->getQuoteId() ?: 0));

        // If we only have increment, resolve entity id
        if (!$orderId && $increment !== '') {
            $orderId = $this->eidFromIncrement($increment);
        }

        // If we only have quote id, resolve order from quote
        if (!$orderId && $quoteId) {
            // Try order from quote_id
            $orderId = $this->eidFromQuoteId($quoteId);
            if ($orderId) {
                // also fetch increment
                $increment = $this->incrementFromEntityId($orderId);
            } else {
                // Try quote's reserved increment
                try {
                    $quote = $this->cartRepository->get($quoteId);
                    $reserved = (string)$quote->getReservedOrderId();
                    if ($reserved !== '') {
                        $orderId   = $this->eidFromIncrement($reserved);
                        $increment = $reserved ?: $increment;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[KlarnaFix] KeysSetter failed loading quote', [
                        'qid' => $quoteId, 'e' => $e->getMessage()
                    ]);
                }
            }
        }

        // If we only have entity id, fetch increment & quote
        if ($orderId && ($increment === '' || !$quoteId)) {
            $order = $this->orderFactory->create()->load($orderId);
            if ($order->getId()) {
                $increment = $increment !== '' ? $increment : (string)$order->getIncrementId();
                $quoteId   = $quoteId ?: (int)$order->getQuoteId();
            }
        }

        // Write back any keys we could determine
        if ($orderId) {
            $session->setLastOrderId($orderId);
        }
        if ($increment !== '') {
            $session->setLastRealOrderId($increment);
        }
        if ($quoteId) {
            $session->setLastQuoteId($quoteId);
            $session->setLastSuccessQuoteId($quoteId);
        }

        $ok = (int)$session->getLastOrderId()
            && (string)$session->getLastRealOrderId() !== ''
            && (int)$session->getLastQuoteId()
            && (int)$session->getLastSuccessQuoteId();

        $this->logger->info('[KlarnaFix] KeysSetter ensure', [
            'ok'        => $ok ? 1 : 0,
            'order_id'  => $session->getLastOrderId(),
            'increment' => $session->getLastRealOrderId(),
            'quote_id'  => $session->getLastQuoteId(),
            'success_q' => $session->getLastSuccessQuoteId(),
        ]);

        return [
            'ok'        => $ok,
            'order_id'  => (int)$session->getLastOrderId(),
            'increment' => (string)$session->getLastRealOrderId(),
            'quote_id'  => (int)$session->getLastQuoteId(),
        ];
    }

    private function eidFromIncrement(string $inc): int
    {
        $order = $this->orderFactory->create()->loadByIncrementId($inc);
        return (int)($order && $order->getId() ? $order->getId() : 0);
    }

    private function eidFromQuoteId(int $qid): int
    {
        $order = $this->orderFactory->create()->getCollection()
            ->addFieldToFilter('quote_id', $qid)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();
        return (int)($order && $order->getId() ? $order->getId() : 0);
    }

    private function incrementFromEntityId(int $eid): string
    {
        $order = $this->orderFactory->create()->load($eid);
        return (string)($order && $order->getId() ? $order->getIncrementId() : '');
    }
}
