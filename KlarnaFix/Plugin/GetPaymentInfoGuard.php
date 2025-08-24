<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Api\Data\PaymentDetailsInterfaceFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

/**
 * Firecheckout sometimes calls getPaymentInformation after redirect.
 * If we *just* placed an order, return an empty, valid PaymentDetails
 * instead of letting it 404 due to an inactive/missing quote.
 */
class GetPaymentInfoGuard
{
    private const SUCCESS_TS = 'gbs_success_t'; // set by SuccessKeys
    private const FRESH_TTL  = 45;              // seconds

    public function __construct(
        private CheckoutSession $checkoutSession,
        private PaymentDetailsInterfaceFactory $detailsFactory,
        private LoggerInterface $logger
    ) {}

    // Works for both customer and guest managers; signatures differ so we use variadic.
    public function aroundGetPaymentInformation($subject, \Closure $proceed, ...$args)
    {
        $freshTs = (int)($this->checkoutSession->getData(self::SUCCESS_TS) ?: 0);
        $lastId  = (int)($this->checkoutSession->getLastOrderId() ?: 0);

        if ($lastId > 0 && $freshTs && (time() - $freshTs) < self::FRESH_TTL) {
            $this->logger->info('[KlarnaFix] GetInfoGuard: returning empty details (fresh success)', [
                'order_id' => $lastId,
                'guest'    => is_string($args[0] ?? null),
            ]);
            return $this->detailsFactory->create(); // valid, empty PaymentDetailsInterface
        }

        return $proceed(...$args);
    }
}
