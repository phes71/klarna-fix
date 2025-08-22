<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Model\ConfigProvider\UrlConfig;
use Magento\Framework\UrlInterface;

class UrlConfigSkipCookie
{
    public function __construct(private UrlInterface $url) {}

    /** After Klarna builds its frontend URLs, swap redirect_url to success */
    public function afterGetConfig(UrlConfig $subject, array $result): array
    {
        $result['redirect_url'] = $this->url->getUrl('checkout/onepage/success');
        return $result;
    }
}
