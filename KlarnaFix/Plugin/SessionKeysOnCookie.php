<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Klarna\Kp\Controller\Klarna\Cookie;
use GerrardSBS\KlarnaFix\Model\KeysSetter;

/**
 * Also enforce keys when Klarna hits /checkout/klarna/cookie.
 */
class SessionKeysOnCookie
{
    public function __construct(private KeysSetter $keysSetter) {}

    public function afterExecute(Cookie $subject, $result)
    {
        $this->keysSetter->ensure();
        return $result;
    }
}
