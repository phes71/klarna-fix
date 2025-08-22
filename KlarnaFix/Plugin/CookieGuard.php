<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Controller\Klarna\Cookie as Subject;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;

/**
 * Prevents a second navigation to the success page caused by Klarna's /checkout/klarna/cookie/
 * when we're already arriving at success. Returns HTTP 204 to stop the redirect chain.
 */
class CookieGuard
{
    public function __construct(
        private RequestInterface $request,
        private RawFactory $rawFactory,
        private LoggerInterface $logger
    ) {}

    public function aroundExecute(Subject $subject, \Closure $proceed)
    {
        $ref = (string)($this->request->getServer('HTTP_REFERER') ?? '');
        if (strpos($ref, '/checkout/onepage/success') !== false) {
            $this->logger->info('[KlarnaFix] CookieGuard: short-circuit on success referer');
            $raw = $this->rawFactory->create();
            $raw->setHttpResponseCode(204);
            return $raw;
        }
        return $proceed();
    }
}
