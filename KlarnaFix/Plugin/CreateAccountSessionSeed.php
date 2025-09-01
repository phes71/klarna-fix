<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Swissup\CheckoutRegistration\Observer\CreateAccount as Target;
use Magento\Framework\Event\Observer;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class CreateAccountSessionSeed
{
    private const KEY_HASH = 'gbs_reg_hash';
    private const KEY_WANT = 'gbs_reg_want';

    public function __construct(
        private CustomerSession $customerSession,
        private LoggerInterface $logger
    ) {}

    public function beforeExecute(Target $subject, Observer $observer): void
    {
        try {
            $order = $observer->getOrder();
            if (!$order || !$order->getId()) return;

            $p = $order->getPayment();
            if (!$p) return;

            $want = (int)$p->getAdditionalInformation(self::KEY_WANT) === 1;
            $hash = (string)$p->getAdditionalInformation(self::KEY_HASH);

            if ($want && $hash !== '') {
                // Swissup’s observer looks for this session value
                $this->customerSession->setRegistrationPasswordHash($hash);
                $this->logger->info('[KlarnaFix] Seeded registration hash from order payment', [
                    'order_id' => $order->getId(), 'increment' => $order->getIncrementId()
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[KlarnaFix] CreateAccountSessionSeed failed', ['e' => $e->getMessage()]);
        }
    }
}
