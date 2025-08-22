<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use GerrardSBS\KlarnaFix\Model\KeysSetter;
use Klarna\Kp\Controller\Klarna\Cookie as KlarnaCookie;
use Psr\Log\LoggerInterface;

class SessionKeysOnCookie
{
    public function __construct(
        private KeysSetter $keysSetter,
        private LoggerInterface $logger
    ) {}

    public function afterExecute(KlarnaCookie $subject, $result)
    {
        $info = $this->keysSetter->ensure();
        $this->logger->info('[KlarnaFix] after Cookie ensure keys', $info);
        return $result;
    }
}
