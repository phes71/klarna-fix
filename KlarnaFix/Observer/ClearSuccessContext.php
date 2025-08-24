<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ClearSuccessContext implements ObserverInterface
{
    public function __construct(
        private Session $session,
        private LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        $this->session->unsetData('gbs_success_seen');
        $this->session->unsetData('gbs_success_t');
        $this->session->unsetData('gbs_klarna_lock_until');
        $this->session->unsetData('gbs_success_cart');

        // Also clear Magento "last*" so the next order isn’t short-circuited.
        $this->session->setLastOrderId(null);
        $this->session->setLastRealOrderId(null);
        $this->session->setLastSuccessQuoteId(null);

        $this->logger->info('[KlarnaFix] cleared success flag + last* ids');
    }
}
