<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Block\Onepage\Success as SuccessBlock;
use Magento\Framework\Registry;
use Magento\Checkout\Model\Session as CheckoutSession;

class SuccessViewPatch
{
    public function __construct(
        private Registry $registry,
        private CheckoutSession $session
    ) {}

    public function afterGetOrderId(SuccessBlock $subject, $result)
    {
        if ($result) return $result;

        $order = $this->registry->registry('current_order') ?: $this->registry->registry('order');
        if ($order && $order->getId()) {
            // backfill so anything else on the page keeps working
            $this->session->setLastOrderId((int)$order->getId());
            $this->session->setLastRealOrderId((string)$order->getIncrementId());
            return (string)$order->getIncrementId();
        }
        return $result;
    }

    public function afterGetOrderDate(SuccessBlock $subject, $result)
    {
        if ($result) return $result;

        $order = $this->registry->registry('current_order') ?: $this->registry->registry('order');
        return ($order && $order->getId()) ? $order->getCreatedAtFormatted(1) : $result;
    }
}