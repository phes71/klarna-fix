<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Webapi\Controller\Rest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

class WebapiPaymentInfoGuard
{
    private const SUCCESS_TS = 'gbs_success_t';
    private const TTL        = 60; // seconds

    public function __construct(
        private CheckoutSession $session,
        private RestResponse $response,
        private Json $json,
        private LoggerInterface $logger
    ) {}

    public function aroundDispatch(Rest $subject, \Closure $proceed, RequestInterface $request)
    {
        $path = $request->getPathInfo(); // e.g. /rest/default/V1/guest-carts/xxxx/payment-information

        // Intercept only the “payment-information” & “payment-details” endpoints (guest + mine)
        $isPaymentInfo =
            (str_contains($path, '/V1/guest-carts/') && str_ends_with($path, '/payment-information')) ||
            (str_contains($path, '/V1/carts/mine/payment-information')) ||
            (str_contains($path, '/V1/guest-carts/') && str_ends_with($path, '/payment-details')) ||
            (str_contains($path, '/V1/carts/mine/payment-details'));

        if (!$isPaymentInfo) {
            return $proceed($request);
        }

        $freshTs   = (int)($this->session->getData(self::SUCCESS_TS) ?: 0);
        $isFresh   = $freshTs && (time() - $freshTs) < self::TTL;
        $hasOrder  = (int)($this->session->getLastOrderId() ?: 0) > 0;
        $hasSuccQ  = (int)($this->session->getLastSuccessQuoteId() ?: 0) > 0;

        // If we already have an order or we’re within the short post-success window, mask it.
        $shouldMask = $isFresh || $hasOrder || $hasSuccQ;

        $this->logger->info('[KlarnaFix] WebapiPaymentInfoGuard hit', [
            'path'      => $path,
            'isFresh'   => $isFresh ? 1 : 0,
            'hasOrder'  => $hasOrder ? 1 : 0,
            'hasSuccQ'  => $hasSuccQ ? 1 : 0,
        ]);

        if ($shouldMask) {
            // If we raced the timestamp, stamp it now to cover follow-ups from the same page load
            if (!$isFresh) {
                $this->session->setData(self::SUCCESS_TS, time());
            }

            // Minimal valid payload for Magento’s checkout UI
            $payload = ['payment_methods' => [], 'totals' => null];

            $this->response->setHttpResponseCode(200);
            $this->response->setHeader('Content-Type', 'application/json', true);
            $this->response->setBody($this->json->serialize($payload));

            $this->logger->info('[KlarnaFix] WebapiPaymentInfoGuard short-circuited with 200');
            return $this->response;
        }

        return $proceed($request);
    }
}
