<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Controller\Klarna\QuoteStatus;
use GerrardSBS\KlarnaFix\Model\KeysSetter;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class QuoteStatusFix
{
    public function __construct(
        private CheckoutSession $checkoutSession,
        private OrderFactory $orderFactory,
        private CartRepositoryInterface $cartRepository,
        private KeysSetter $keysSetter,
        private LoggerInterface $logger
    ) {}

    public function beforeExecute(QuoteStatus $subject): void
    {
        $req   = $subject->getRequest();
        $path  = $req->getPathInfo();
        $raw   = (string)$req->getContent();
        $param = $req->getParams();

        $this->logger->info('[KlarnaFix] beforeExecute start', [
            'path'               => $path,
            'params'             => $param,
            'raw'                => $raw,
            'lastOrderId'        => $this->checkoutSession->getLastOrderId(),
            'lastRealOrderId'    => $this->checkoutSession->getLastRealOrderId(),
            'quoteId'            => $this->checkoutSession->getQuoteId(),
            'lastSuccessQuoteId' => $this->checkoutSession->getLastSuccessQuoteId(),
        ]);

        // Use incoming order id if present
        $payload = json_decode($raw, true);
        $incomingId = null;
        if (is_array($payload)) {
            $incomingId = $payload['order_id'] ?? $payload['id'] ?? ($payload['data']['order_id'] ?? null);
        }
        $incomingId = (int)($incomingId ?: ($param['order_id'] ?? $param['id'] ?? 0));
        if ($incomingId > 0) {
            $this->checkoutSession->setLastOrderId($incomingId);
            if (!$req->getParam('order_id')) {
                $req->setParam('order_id', $incomingId);
                $this->logger->info('[KlarnaFix] injected request param order_id', ['order_id' => $incomingId]);
            }
            $this->keysSetter->ensure();
            return;
        }

        // Fallbacks (increment → quote → reserved increment)
        $inc = (string)$this->checkoutSession->getLastRealOrderId();
        if ($inc !== '') {
            $eid = $this->orderFactory->create()->loadByIncrementId($inc)->getId();
            if ($eid) {
                $this->checkoutSession->setLastOrderId((int)$eid);
                if (!$req->getParam('order_id')) {
                    $req->setParam('order_id', (int)$eid);
                    $this->logger->info('[KlarnaFix] set lastOrderId from increment', ['eid' => (int)$eid, 'inc' => $inc]);
                }
                $this->keysSetter->ensure();
                return;
            }
        }

        $qid = (int)($this->checkoutSession->getLastSuccessQuoteId() ?: $this->checkoutSession->getQuoteId());
        if ($qid) {
            $order = $this->orderFactory->create()->getCollection()
                ->addFieldToFilter('quote_id', $qid)
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1)
                ->getFirstItem();

            if ($order->getId()) {
                $this->checkoutSession->setLastOrderId((int)$order->getId());
                $this->checkoutSession->setLastRealOrderId((string)$order->getIncrementId());
                if (!$req->getParam('order_id')) {
                    $req->setParam('order_id', (int)$order->getId());
                    $this->logger->info('[KlarnaFix] set lastOrderId from quote_id', [
                        'eid' => (int)$order->getId(), 'qid' => $qid
                    ]);
                }
                $this->keysSetter->ensure();
                return;
            }

            // reserved increment
            try {
                $quote = $this->cartRepository->get($qid);
                $reserved = (string)$quote->getReservedOrderId();
                if ($reserved !== '') {
                    $eid = $this->orderFactory->create()->loadByIncrementId($reserved)->getId();
                    if ($eid) {
                        $this->checkoutSession->setLastOrderId((int)$eid);
                        $this->checkoutSession->setLastRealOrderId($reserved);
                        if (!$req->getParam('order_id')) {
                            $req->setParam('order_id', (int)$eid);
                            $this->logger->info('[KlarnaFix] set lastOrderId from reserved increment', [
                                'eid' => (int)$eid, 'reserved' => $reserved, 'qid' => $qid
                            ]);
                        }
                        $this->keysSetter->ensure();
                        return;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[KlarnaFix] failed loading quote for reserved increment', [
                    'qid' => $qid, 'e' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('[KlarnaFix] could not derive order id yet');
    }
}
