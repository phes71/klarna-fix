<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class PersistRegistrationIntent
{
    private const KEY_HASH = 'gbs_reg_hash';
    private const KEY_WANT = 'gbs_reg_want';

    public function __construct(
        private CheckoutSession $checkoutSession,
        private CartRepositoryInterface $quoteRepo,
        private RequestInterface $request,
        private CustomerSession $customerSession,    // ğŸ‘ˆ use CustomerSession for the hash
        private LoggerInterface $logger
    ) {}

    public function aroundSavePaymentInformation($subject, \Closure $proceed, ...$args)
    {
        $this->persist($args);
        return $proceed(...$args);
    }

    public function aroundSavePaymentInformationAndPlaceOrder($subject, \Closure $proceed, ...$args)
    {
        $this->persist($args);
        return $proceed(...$args);
    }

    private function persist(array $args): void
{
    try {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) return;

        // 1) Gather inputs
        $params = (array)$this->request->getParams();
        $want = false;
        $wantSource = 'none';

        // Try common keys used by one-step checkouts
        $truthy = static function($v): bool {
            if (is_bool($v)) return $v;
            if (is_numeric($v)) return ((int)$v) === 1;
            $v = strtolower(trim((string)$v));
            return in_array($v, ['1','true','yes','on'], true);
        };

        $keys = ['register_account','create_account','createaccount','account_create','is_create_account','register'];
        foreach ($keys as $k) {
            if (!$want && array_key_exists($k, $params) && $truthy($params[$k])) {
                $want = true; $wantSource = "param:$k";
            }
        }

        // Nested arrays (some themes put it under billing / account sections)
        if (!$want) {
            foreach ($params as $k => $v) {
                if (is_array($v) && isset($v['create_account']) && $truthy($v['create_account'])) {
                    $want = true; $wantSource = "nested:$k.create_account";
                    break;
                }
            }
        }

        // 2) Registration password HASH (if Swissup provided it)
        $hash = (string)($this->customerSession->getRegistrationPasswordHash() ?? '');

        // If a hash exists, treat it as a strong intent signal even if no checkbox param
        if (!$want && $hash !== '') {
            $want = true; $wantSource = 'hash_only';
        }

        // 3) Also accept values coming via payment additionalData
        foreach ($args as $a) {
            if ($a instanceof \Magento\Quote\Api\Data\PaymentInterface) {
                $add = (array)($a->getAdditionalData() ?? []);

                if (!$want && isset($add['register_account']) && $truthy($add['register_account'])) {
                    $want = true; $wantSource = 'additionalData:register_account';
                }
                if (!$want && isset($add['create_account']) && $truthy($add['create_account'])) {
                    $want = true; $wantSource = 'additionalData:create_account';
                }
                if ($hash === '' && !empty($add['registration_password_hash'])) {
                    $hash = (string)$add['registration_password_hash'];
                }
            }
        }

        $payment = $quote->getPayment();
        if (!$payment) {
            $this->logger->info('[KlarnaFix] PersistRegistrationIntent: no payment on quote', ['quote_id' => $quote->getId()]);
            return;
        }

        if ($want) {
            // Persist "want" even without a hash; persist hash when present
            $payment->setAdditionalInformation(self::KEY_WANT, 1);
            if ($hash !== '') {
                $payment->setAdditionalInformation(self::KEY_HASH, $hash);
            }
            $this->quoteRepo->save($quote);

            $this->logger->info('[KlarnaFix] PersistRegistrationIntent: persisted intent', [
                'quote_id' => $quote->getId(),
                'want'     => 1,
                'hash'     => $hash !== '' ? 1 : 0,
                'source'   => $wantSource,
            ]);
        } else {
            // Be explicit about guest path and clear any stale hash to avoid false positives later
            $this->customerSession->unsRegistrationPasswordHash();
            $payment->unsAdditionalInformation(self::KEY_WANT)
                    ->unsAdditionalInformation(self::KEY_HASH);
            $this->quoteRepo->save($quote);

            $this->logger->info('[KlarnaFix] PersistRegistrationIntent: guest path — cleared stale reg data');
        }
    } catch (\Throwable $e) {
        $this->logger->warning('[KlarnaFix] PersistRegistrationIntent failed', ['e' => $e->getMessage()]);
    }
}


}
