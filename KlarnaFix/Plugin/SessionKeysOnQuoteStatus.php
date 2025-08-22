<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use GerrardSBS\KlarnaFix\Model\KeysSetter;
use Klarna\Kp\Controller\Klarna\QuoteStatus;
use Psr\Log\LoggerInterface;

class SessionKeysOnQuoteStatus
{
    public function __construct(
        private KeysSetter $keysSetter,
        private LoggerInterface $logger
    ) {}

    public function afterExecute(QuoteStatus $subject, $result)
    {
        $info = $this->keysSetter->ensure();
        $this->logger->info('[KlarnaFix] after QuoteStatus ensure keys', $info);
        return $result;
    }
}
