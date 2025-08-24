<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Model\OrderFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

class KeysSetter
{
    public function __construct(
        private CheckoutSession $session,
        private OrderFactory $orderFactory,
        private CartRepositoryInterface $cartRepo,
        private LoggerInterface $logger
    ) {}

    public function ensure(): void
    {
        try {
            // Prefer the CURRENT active quote first
            $activeQid = 0;
            try {
                $activeQid = (int)($this->session->getQuote()->getId() ?: 0);
            } catch (\Throwable $e) {}

            $inc = (string)($this->session->getLastRealOrderId() ?: '');
            $qid = $activeQid
                ?: (int)($this->session->getLastQuoteId() ?: 0)
                ?: (int)($this->session->getLastSuccessQuoteId() ?: 0);

            $resolved = null;

            // Try resolve by increment — but ONLY if it matches the active quote (when known)
            if ($inc !== '') {
                $o = $this->orderFactory->create()->loadByIncrementId($inc);
                if ($o->getId()) {
                    if ($activeQid && (int)$o->getQuoteId() !== $activeQid) {
                        // Stale increment from previous order – ignore it
                        $this->session->setLastRealOrderId('');
                        $inc = '';
                    } else {
                        $resolved = $o;
                    }
                }
            }

            // Else resolve by quote id
            if (!$resolved && $qid) {
                $o = $this->orderFactory->create()->getCollection()
                    ->addFieldToFilter('quote_id', $qid)
                    ->setOrder('entity_id', 'DESC')
                    ->setPageSize(1)
                    ->getFirstItem();
                if ($o && $o->getId()) {
                    $resolved = $o;
                }
            }

            if ($resolved) {
                $this->session->setLastOrderId((int)$resolved->getId());
                $this->session->setLastRealOrderId((string)$resolved->getIncrementId());

                $oqid = (int)$resolved->getQuoteId();
                if ($oqid) {
                    $this->session->setLastQuoteId($oqid);
                    $this->session->setLastSuccessQuoteId($oqid);
                }
            } elseif ($qid) {
                // Keep quote ids in sync even if order not yet resolvable
                $this->session->setLastQuoteId($qid);
                $this->session->setLastSuccessQuoteId($qid);
            }

            $this->logger->info('[KlarnaFix] KeysSetter ensure', [
                'order_id' => (int)$this->session->getLastOrderId(),
                'increment'=> (string)$this->session->getLastRealOrderId(),
                'quote_id' => (int)$this->session->getLastSuccessQuoteId(),
                'active_q' => $activeQid,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[KlarnaFix] KeysSetter failed', ['e' => $e->getMessage()]);
        }
    }
}
