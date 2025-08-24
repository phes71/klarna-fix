<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Controller\Klarna\QuoteStatus;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * Before Klarna's QuoteStatus executes, try to ensure the checkout session
 * has last order / last real order / last success quote ids.
 * This is critical for GUEST flows where redirect order depends on these keys.
 */
class QuoteStatusFix
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private OrderFactory $orderFactory,
        private CartRepositoryInterface $cartRepository,
        private LoggerInterface $logger
    ) {}

    public function beforeExecute(QuoteStatus $subject): void
    {
        $req = $subject->getRequest();
        $raw = (string)$req->getContent();
        $param = $req->getParams();

        $this->logger->info('[KlarnaFix] QS beforeExecute', [
            'path'               => $req->getPathInfo(),
            'params'             => $param,
            'raw'                => $raw,
            'lastOrderId'        => $this->checkoutSession->getLastOrderId(),
            'lastRealOrderId'    => $this->checkoutSession->getLastRealOrderId(),
            'quoteId'            => $this->checkoutSession->getQuoteId(),
            'lastSuccessQuoteId' => $this->checkoutSession->getLastSuccessQuoteId(),
        ]);

        // 0) If the request already carries an order id, set it.
        $incomingId = null;
        if ($raw !== '') {
            $payload = json_decode($raw, true);
            if (is_array($payload)) {
                $incomingId = $payload['order_id'] ?? $payload['id'] ?? ($payload['data']['order_id'] ?? null);
            }
        }
        $incomingId = (int)($incomingId ?: ($param['order_id'] ?? $param['id'] ?? 0));
        if ($incomingId > 0) {
            $this->primeOrder((int)$incomingId);
            $req->setParam('order_id', (int)$incomingId);
            $this->logger->info('[KlarnaFix] QS used incoming order id', ['order_id' => (int)$incomingId]);
            return;
        }

        // 1) Try from last increment id
        $inc = (string)$this->checkoutSession->getLastRealOrderId();
        if ($inc !== '' && ($eid = $this->entityIdFromIncrement($inc))) {
            $this->primeOrder($eid, $inc);
            $this->logger->info('[KlarnaFix] QS set from increment', ['eid' => $eid, 'inc' => $inc]);
            return;
        }

        // 2) From quote id → order by quote_id
        $qid = (int)($this->checkoutSession->getLastSuccessQuoteId() ?: $this->checkoutSession->getQuoteId());
        if ($qid && ($eid = $this->entityIdFromQuoteId($qid))) {
            $this->primeOrder($eid);
            $this->checkoutSession->setLastQuoteId($qid);
            $this->checkoutSession->setLastSuccessQuoteId($qid);
            $this->logger->info('[KlarnaFix] QS set from quote_id', ['eid' => $eid, 'qid' => $qid]);
            return;
        }

        // 3) Last resort: quote's reserved increment (if any)
        if ($qid) {
            try {
                $quote = $this->cartRepository->get($qid);
                $reserved = (string)$quote->getReservedOrderId();
                if ($reserved !== '' && ($eid = $this->entityIdFromIncrement($reserved))) {
                    $this->primeOrder($eid, $reserved);
                    $this->checkoutSession->setLastQuoteId($qid);
                    $this->checkoutSession->setLastSuccessQuoteId($qid);
                    $this->logger->info('[KlarnaFix] QS set from reserved increment', [
                        'eid' => $eid, 'reserved' => $reserved, 'qid' => $qid
                    ]);
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[KlarnaFix] QS failed loading quote', [
                    'qid' => $qid, 'e' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('[KlarnaFix] QS could not derive order id yet');
    }

    private function primeOrder(int $entityId, ?string $increment = null): void
    {
        $this->checkoutSession->setLastOrderId($entityId);
        if ($increment) {
            $this->checkoutSession->setLastRealOrderId($increment);
        }
    }

    private function entityIdFromIncrement(string $increment): int
    {
        $order = $this->orderFactory->create()->loadByIncrementId($increment);
        return (int)($order && $order->getEntityId() ? $order->getEntityId() : 0);
    }

    private function entityIdFromQuoteId(int $quoteId): int
    {
        $order = $this->orderFactory->create()->getCollection()
            ->addFieldToFilter('quote_id', $quoteId)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();
        return (int)($order && $order->getEntityId() ? $order->getEntityId() : 0);
    }
}
