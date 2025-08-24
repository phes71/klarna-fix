<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class ClearSuccessFlag
{
    public function __construct(
        private CheckoutSession $session,
        private LoggerInterface $logger
    ) {}

    public function beforeExecute($subject): void
    {
        // fresh journey → drop previous success state
        $this->session->unsetData('gbs_success_seen');
        $this->session->unsetData('gbs_klarna_lock_until');
        $this->session->unsetData('last_order_id');
        $this->session->unsetData('last_real_order_id');
        $this->session->unsetData('last_success_quote_id');
        $this->logger->info('[KlarnaFix] cleared success flag + last* ids');
    }
}
