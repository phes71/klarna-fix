<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class ClearSuccessFlag
{
    public function __construct(
        private CheckoutSession $session,
		private \Magento\Customer\Model\Session $customerSession, 
        private LoggerInterface $logger
    ) {}

    public function beforeExecute($subject): void
{
    // clear Swissup reg hash first
    $this->customerSession->unsRegistrationPasswordHash();

    // clear our success markers
    $this->session->unsetData('gbs_success_t');
    $this->session->unsetData('gbs_success_cart');
    $this->session->unsetData('gbs_klarna_lock_until');
	$this->session->unsetData('gbs_autologin_done');
    // clear Magento last* ids
    $this->session->setLastOrderId(null);
    $this->session->setLastRealOrderId(null);
    $this->session->setLastQuoteId(null);          // ← missing
    $this->session->setLastSuccessQuoteId(null);

    $this->logger->info('[KlarnaFix] cleared success flag + last* ids + reg-hash');
}
}
