<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Api\Data\PaymentDetailsInterfaceFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class GetPaymentInfoGuard
{
    private const SUCCESS_TS = 'gbs_success_t'; // primed in CookieGuard + SuccessKeys
    private const FRESH_TTL  = 60;              // seconds; keep modest

    public function __construct(
        private CheckoutSession $checkoutSession,
        private PaymentDetailsInterfaceFactory $detailsFactory,
        private LoggerInterface $logger
    ) {}

    /** Shared predicate */
    private function shouldShortCircuit(?string $cartId): bool
    {
        $freshTs     = (int)($this->checkoutSession->getData(self::SUCCESS_TS) ?: 0);
        $lastOrderId = (int)($this->checkoutSession->getLastOrderId() ?: 0);
        $successCart = (string)($this->checkoutSession->getData('gbs_success_cart') ?? '');

        $isFresh  = $freshTs && (time() - $freshTs) < self::FRESH_TTL;
        $matches  = $cartId && $successCart && hash_equals($successCart, $cartId);

        $this->logger->info('[KlarnaFix] GetInfoGuard predicate', [
            'cartId'      => $cartId,
            'hasOrder'    => $lastOrderId > 0,
            'isFresh'     => $isFresh,
            'cartMatches' => $matches,
        ]);

        return ($lastOrderId > 0) || $isFresh || $matches;
    }

    
    public function aroundGetPaymentInformation($subject, \Closure $proceed, ...$args)
    {
        // arg[0] is cartId for guest; empty for customer (me)
        $cartId = (string)($args[0] ?? '');

        $this->logger->info('[KlarnaFix] GetPaymentInfoGuard hit: getPaymentInformation via ' . get_class($subject));


        if ($this->shouldShortCircuit($cartId)) {
            $this->logger->info('[KlarnaFix] GetInfoGuard → empty PaymentDetails (getPaymentInformation)');
            return $this->detailsFactory->create();
        }

        try {
            return $proceed(...$args);
        } catch (NoSuchEntityException $e) {
            $this->logger->info('[KlarnaFix] GetInfoGuard swallowed NSEE (getPaymentInformation)');
            return $this->detailsFactory->create();
        }
    }

    
    public function aroundGetPaymentDetails($subject, \Closure $proceed, ...$args)
    {
        // arg[0] is cartId
        $cartId = (string)($args[0] ?? '');

        $this->logger->info('[KlarnaFix] GetPaymentInfoGuard hit: getPaymentDetails');

        if ($this->shouldShortCircuit($cartId)) {
            $this->logger->info('[KlarnaFix] GetInfoGuard → empty PaymentDetails (getPaymentDetails)');
            return $this->detailsFactory->create();
        }

        try {
            return $proceed(...$args);
        } catch (NoSuchEntityException $e) {
            $this->logger->info('[KlarnaFix] GetInfoGuard swallowed NSEE (getPaymentDetails)');
            return $this->detailsFactory->create();
        }
    }
}
