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
        private CustomerSession $customerSession,
        private LoggerInterface $logger
    ) {}

    private function isKlarna(?string $code): bool
    {
        if (!$code) return false;
        $code = strtolower($code);
        return $code === 'klarna_pay_now'
            || $code === 'klarna_pay_later'
            || $code === 'klarna_slice_it'
            || str_starts_with($code, 'klarna'); // future-proof
    }

    /** Shipping step (both guest & logged-in managers call the same method name) */
    public function aroundSaveAddressInformation($subject, \Closure $proceed, ...$args)
    {
        $this->persist($args, 'shipping');
        return $proceed(...$args);
    }

    /** Payment step */
    public function aroundSavePaymentInformation($subject, \Closure $proceed, ...$args)
    {
        $this->persist($args, 'payment');
        return $proceed(...$args);
    }

    /** PlaceOrder combo (some flows hit this directly) */
    public function aroundSavePaymentInformationAndPlaceOrder($subject, \Closure $proceed, ...$args)
    {
        $this->persist($args, 'payment');
        return $proceed(...$args);
    }

    private function persist(array $args, string $stage): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) return;

            $payment = $quote->getPayment();
            $method  = (string)($payment?->getMethod() ?? '');

            /**
             * We only restrict by PSP at the PAYMENT stage.
             * At the SHIPPING stage, payment code may not be decided yet,
             * so we still want to capture the user's “create account” intent.
             */
            if ($stage !== 'shipping' && $method && !$this->isKlarna($method)) {
                return; // do not touch non-Klarna flows in payment step
            }

            $params = (array)$this->request->getParams();
            $want = false;
            $wantSource = 'none';

            $truthy = static function ($v): bool {
                if (is_bool($v)) return $v;
                if (is_numeric($v)) return ((int)$v) === 1;
                $v = strtolower(trim((string)$v));
                return in_array($v, ['1','true','yes','on'], true);
            };

            $keys = ['register_account','create_account','createaccount','account_create','is_create_account','register'];

            // a) query/POST
            foreach ($keys as $k) {
                if (!$want && array_key_exists($k, $params) && $truthy($params[$k])) {
                    $want = true; $wantSource = "param:$k";
                }
            }
            if (!$want) {
                foreach ($params as $k => $v) {
                    if (is_array($v) && isset($v['create_account']) && $truthy($v['create_account'])) {
                        $want = true; $wantSource = "nested:$k.create_account";
                        break;
                    }
                }
            }

            // b) Swissup session hash
            $hash = (string)($this->customerSession->getRegistrationPasswordHash() ?? '');
            if (!$want && $hash !== '') {
                $want = true; $wantSource = 'hash_only';
            }

            // c) Payload objects (shipping + payment)
            foreach ($args as $idx => $a) {
                // ShippingInformationInterface: read extension attributes (Swissup)
                if (is_object($a) && interface_exists(\Magento\Checkout\Api\Data\ShippingInformationInterface::class)
                    && $a instanceof \Magento\Checkout\Api\Data\ShippingInformationInterface) {

                    $ext = $a->getExtensionAttributes();
                    if ($ext) {
                        // Swissup uses registrationCheckboxState(), but be defensive
                        if (!$want) {
                            $chk = null;
                            if (method_exists($ext, 'getRegistrationCheckboxState')) {
                                $chk = $ext->getRegistrationCheckboxState();
                            } elseif (method_exists($ext, 'getRegisterAccount')) {
                                $chk = $ext->getRegisterAccount();
                            }
                            if ($chk !== null && $truthy($chk)) {
                                $want = true; $wantSource = 'shipping_ext:checkbox';
                            }
                        }
                        // If Swissup put hash into session already, we’ve captured it above.
                    }
                    continue;
                }

                // PaymentInterface additional_data
                if (is_object($a) && $a instanceof \Magento\Quote\Api\Data\PaymentInterface) {
                    $add = (array)($a->getAdditionalData() ?? []);
                    if (!$want && isset($add['register_account']) && $truthy($add['register_account'])) {
                        $want = true; $wantSource = 'additionalData:register_account';
                    }
                    if (!$want && isset($add['create_account']) && $truthy($add['create_account'])) {
                        $want = true; $wantSource = 'additionalData:create_account';
                    }
                    if ($hash === '' && !empty($add['registration_password_hash'])) {
                        $hash = (string)$add['registration_password_hash'];
                        if (!$want) { $want = true; $wantSource = 'additionalData:hash_only'; }
                    }
                    continue;
                }

                // Some integrations pass arrays instead of interfaces
                if (is_array($a) && isset($a['additional_data']) && is_array($a['additional_data'])) {
                    $add = $a['additional_data'];
                    if (!$want && isset($add['register_account']) && $truthy($add['register_account'])) {
                        $want = true; $wantSource = 'additionalDataArr:register_account';
                    }
                    if (!$want && isset($add['create_account']) && $truthy($add['create_account'])) {
                        $want = true; $wantSource = 'additionalDataArr:create_account';
                    }
                    if ($hash === '' && !empty($add['registration_password_hash'])) {
                        $hash = (string)$add['registration_password_hash'];
                        if (!$want) { $want = true; $wantSource = 'additionalDataArr:hash_only'; }
                    }
                }
            }

            // d) REST JSON body (mobile)
            try {
                $raw = (string)$this->request->getContent();
                if ($raw !== '') {
                    $body = json_decode($raw, true);
                    if (is_array($body)) {
                        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($body));
                        foreach ($it as $k => $v) {
                            $kk = strtolower((string)$k);
                            if (!$want && in_array($kk, $keys, true) && $truthy($v)) {
                                $want = true; $wantSource = 'body';
                                break;
                            }
                        }
                        $maybe = $body['paymentMethod']['additional_data']['registration_password_hash'] ?? '';
                        if ($hash === '' && is_string($maybe) && $maybe !== '') {
                            $hash = $maybe;
                            if (!$want) { $want = true; $wantSource = 'body:hash_only'; }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore diagnostics errors
            }

            // Persist on the quote payment (safe for non-Klarna; only Klarna uses it at success)
            if (!$payment) {
                $this->logger->info('[KlarnaFix] PersistRegistrationIntent: no payment on quote', ['quote_id' => $quote->getId()]);
                return;
            }

            if ($want || $hash !== '') {
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
                    'stage'    => $stage,
                    'method'   => $method ?: '(none)',
                ]);
            } else {
                // Guest path: don’t wipe the Swissup session hash here; just keep our flags off
                $payment->unsAdditionalInformation(self::KEY_WANT)
                        ->unsAdditionalInformation(self::KEY_HASH);
                $this->quoteRepo->save($quote);
                $this->logger->info('[KlarnaFix] PersistRegistrationIntent: guest path — left session hash intact, cleared payment flags', [
                    'stage'  => $stage,
                    'method' => $method ?: '(none)',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[KlarnaFix] PersistRegistrationIntent failed', ['e' => $e->getMessage()]);
        }
    }
}
