<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Controller\Klarna\Cookie;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;
use GerrardSBS\KlarnaFix\Model\KeysSetter;

class CookieGuard
{
    private const LOCK_KEY   = 'gbs_klarna_lock_until';
    private const LOCK_TTL   = 12;
    private const SUCCESS_TS = 'gbs_success_t';
    private const SUCCESS_TTL= 15; // seconds

    public function __construct(
        private CheckoutSession  $checkoutSession,
        private ResultFactory    $resultFactory,
        private RequestInterface $request,
        private LoggerInterface  $logger,
        private KeysSetter       $keysSetter
    ) {}

    public function aroundExecute(Cookie $subject, \Closure $proceed)
    {
        $now   = time();
        $until = (int)($this->checkoutSession->getData(self::LOCK_KEY) ?: 0);
        $ref   = (string)($this->request->getServer('HTTP_REFERER') ?? '');

        $xrw    = strtolower((string)$this->request->getHeader('X-Requested-With'));
        $sfm    = (string)$this->request->getHeader('Sec-Fetch-Mode');
        $accept = (string)$this->request->getHeader('Accept');
        $isAjax = ($xrw === 'xmlhttprequest')
               || in_array($sfm, ['cors','no-cors','same-origin'], true)
               || str_contains($accept, 'application/json');

        // Make sure session keys are consistent first
        $this->keysSetter->ensure();

       	$hasOrder        = (int)$this->checkoutSession->getLastOrderId() > 0;
    	$hasSuccessQuote = (int)$this->checkoutSession->getLastSuccessQuoteId() > 0;

        // Only treat as "success-ready" if it matches current quote or is very fresh
        $activeQid = 0;
        try { $activeQid = (int)($this->checkoutSession->getQuote()->getId() ?: 0); } catch (\Throwable $e) {}
        $lastSuccQ = (int)($this->checkoutSession->getLastSuccessQuoteId() ?: 0);

        $freshTs = (int)($this->checkoutSession->getData(self::SUCCESS_TS) ?: 0);
        $fresh   = $freshTs && ($now - $freshTs) < self::SUCCESS_TTL;

        $matchingContext = $fresh || ($activeQid && $lastSuccQ && $activeQid === $lastSuccQ);

        // Respect lock for top-level nav loops
        if (!$isAjax && $now < $until &&
            (str_contains($ref, '/checkout/onepage/success') || str_contains($ref, '/redirect'))) {
            $raw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $raw->setHttpResponseCode(204);
            $this->logger->info('[KlarnaFix] CookieGuard 204 (locked)', ['referer' => $ref, 'until' => $until]);
            return $raw;
        }

        if (($hasOrder || $hasSuccessQuote) && $matchingContext) {
			// arm the short lock and stamp "fresh success" NOW (before we navigate)
			$this->checkoutSession->setData(self::LOCK_KEY, $now + self::LOCK_TTL);
			$this->checkoutSession->setData(self::SUCCESS_TS, $now);
			if ($isAjax) {
				$json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
				$json->setData(['ok' => true, 'redirect' => '/checkout/onepage/success/']);
				$this->logger->info('[KlarnaFix] CookieGuard JSON → success');
				return $json;
			}

			$redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
			$redirect->setPath('checkout/onepage/success'); // no shareable ?o= anymore
			$this->logger->info('[KlarnaFix] CookieGuard 302 redirect → success (navigate)');
			return $redirect;
		}

        // Let Klarna continue (no success context yet for THIS quote)
        return $proceed();
    }
}
