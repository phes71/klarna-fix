<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

/**
 * If Success decides to redirect to cart but we still hold valid
 * last order identifiers in session, swap that redirect for a success page.
 */
class SuccessGuard
{
    private const SUCCESS_TS  = 'gbs_success_t';
    private const SUCCESS_TTL = 15; // seconds

    public function __construct(
        private CheckoutSession $checkoutSession,
        private PageFactory $pageFactory,
        private LoggerInterface $logger
    ) {}

    public function aroundExecute(Success $subject, \Closure $proceed): ResultInterface
    {
        $result = $proceed();

        if ($result instanceof Redirect) {
            $hasOrder = (int)$this->checkoutSession->getLastOrderId() > 0
                     && (string)$this->checkoutSession->getLastRealOrderId() !== '';

            $freshTs = (int)($this->checkoutSession->getData(self::SUCCESS_TS) ?: 0);
            $fresh   = $freshTs && (time() - $freshTs) < self::SUCCESS_TTL;

            if ($hasOrder && $fresh) {
                $this->logger->info('[KlarnaFix] SuccessGuard: swapped cart redirect → success page', [
                    'lastOrderId'     => (int)$this->checkoutSession->getLastOrderId(),
                    'lastRealOrderId' => (string)$this->checkoutSession->getLastRealOrderId(),
                ]);
                return $this->pageFactory->create();
            }
        }

        return $result;
    }
}