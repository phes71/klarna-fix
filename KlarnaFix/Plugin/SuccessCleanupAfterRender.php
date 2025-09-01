<?php
// app/code/GerrardSBS/KlarnaFix/Plugin/SuccessCleanupAfterRender.php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Framework\App\RequestInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class SuccessCleanupAfterRender
{
    public function __construct(
        private CheckoutSession $session,
        private RequestInterface $request,
        private LoggerInterface $logger
    ) {}

    public function aroundRenderResult(ResultPage $subject, \Closure $proceed, $response)
    {
        $out = $proceed($response);

        if ($this->request->getFullActionName() === 'checkout_onepage_success'
            && $this->session->getData('gbs_success_pending_cleanup')) {

            // consume the tag and expire success context AFTER HTML was sent
            $this->session->unsetData('gbs_success_pending_cleanup');
            $this->session->setData('gbs_success_t', time()); // helps CookieGuard/refresh lock
			$this->session->unsetData('gbs_autologin_done'); 
            $this->session->setLastOrderId(null);
            $this->session->setLastRealOrderId(null);
            $this->session->setLastQuoteId(null);
            $this->session->setLastSuccessQuoteId(null);
            $this->session->unsetData('gbs_success_cart');

            $this->logger->info('[KlarnaFix] SuccessCleanupAfterRender: cleared success context');
        }

        return $out;
    }
}
