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
        // Clear only our own flags. DO NOT clear Magento's last* IDs here.
        // Some gateways (e.g., Clearpay) briefly route via Cart/Checkout
        // before success; clearing last* would break the success context.
        $this->session->unsetData('gbs_success_t');
        $this->session->unsetData('gbs_success_cart');
        $this->session->unsetData('gbs_klarna_lock_until');
        $this->session->unsetData('gbs_autologin_done');

        $this->logger->info('[KlarnaFix] cleared success flag + last* ids (kept Magento last* intact)');
    }
}
