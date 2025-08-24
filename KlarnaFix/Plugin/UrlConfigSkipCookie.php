<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Model\ConfigProvider\UrlConfig;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * Nudge Klarna’s frontend to go straight to success when possible.
 */
class UrlConfigSkipCookie
{
    public function __construct(
        private UrlInterface $urlBuilder,
        private LoggerInterface $logger
    ) {}

    public function afterGetConfig(UrlConfig $subject, array $result): array
    {
        try {
            $result['redirect_url'] = $this->urlBuilder->getUrl('checkout/onepage/success');
            $this->logger->info('[KlarnaFix] UrlConfigSkipCookie set redirect_url', ['url' => $result['redirect_url']]);
        } catch (\Throwable $e) {}
        return $result;
    }
}

