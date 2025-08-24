<?php
// app/code/GerrardSBS/KlarnaFix/Plugin/SuccessAccessGuard.php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class SuccessAccessGuard
{
    public function __construct(
        private CheckoutSession $session,
        private ResultFactory $resultFactory,
        private RequestInterface $request,
        private LoggerInterface $logger
    ) {}

    public function aroundExecute(Success $subject, \Closure $proceed)
    {
        // allow only if we actually have a success context
        $has = (int)$this->session->getLastOrderId() > 0
            || (string)$this->session->getLastRealOrderId() !== '';

        if (!$has) {
            $this->logger->info('[KlarnaFix] SuccessAccessGuard: deny (no context)');
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                ->setPath('checkout/cart');
        }

        // tag this response; the cleanup plugin will clear AFTER render
        $this->session->setData('gbs_success_pending_cleanup', 1);

        return $proceed();
    }
}
