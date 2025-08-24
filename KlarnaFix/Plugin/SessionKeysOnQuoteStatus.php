<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Controller\Klarna\QuoteStatus;
use GerrardSBS\KlarnaFix\Model\KeysSetter;

/**
 * After Klarna’s quoteStatus finishes polling, enforce success keys.
 */
class SessionKeysOnQuoteStatus
{
    public function __construct(private KeysSetter $keysSetter) {}

    public function afterExecute(QuoteStatus $subject, $result)
    {
        $this->keysSetter->ensure();
        return $result;
    }
}
