<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Psr\Log\LoggerInterface;

class AutoLoginOnSuccess
{
    private const KEY_HASH = 'gbs_reg_hash';
    private const KEY_WANT = 'gbs_reg_want';

    public function __construct(
        private CheckoutSession $checkoutSession,
        private CustomerRepositoryInterface $customerRepo,
        private CustomerSession $customerSession,
        private OrderCustomerManagementInterface $orderCustomerService,
        private OrderRepositoryInterface $orderRepo,
        private ScopeConfigInterface $scopeConfig,
        private AddressRepositoryInterface $addressRepository,
        private AddressInterfaceFactory $addressFactory,
        private RegionInterfaceFactory $regionFactory,
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

    public function afterExecute(Success $subject, $result)
    {
        try {
            if ((int)$this->checkoutSession->getData('gbs_autologin_done') === 1) {
                return $result;
            }
            if ($this->customerSession->isLoggedIn()) {
                return $result;
            }

            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order || !$order->getId()) {
                return $result;
            }

            $websiteId = (int)$order->getStore()->getWebsiteId();
            $email     = trim((string)$order->getCustomerEmail());
            $method    = (string)($order->getPayment()?->getMethod() ?? '');

            // 1) Already linked? -> login (all gateways)
            $customerId = (int)$order->getCustomerId();
            if ($customerId > 0) {
                $customer = $this->customerRepo->getById($customerId);
                $this->customerSession->setCustomerDataAsLoggedIn($customer);
                $this->checkoutSession->setData('gbs_autologin_done', 1);
                $this->cleanupRegistrationIntent($order); // clears only for Klarna
                $this->backfillAddressesFromOrder($customer, $order);
                $this->logger->info('[KlarnaFix] AutoLoginOnSuccess: logged customer in', [
                    'customer_id' => $customerId, 'order' => $order->getIncrementId()
                ]);
                return $result;
            }

            // 2) Existing account by email? -> login (all gateways)
            
            // 3) From here on, only Klarna should trigger "create customer from order"
            if (!$this->isKlarna($method)) {
                // Non-Klarna gateways rely on Swissup’s flow using the session hash.
                return $result;
            }

            // 3a) Decide “wants account” (Klarna only)
            $p = $order->getPayment();
            $flagWant      = ($p && (int)$p->getAdditionalInformation(self::KEY_WANT) === 1) ? 1 : 0;
            $hashOnPayment = ($p && (string)$p->getAdditionalInformation(self::KEY_HASH) !== '') ? 1 : 0;
            $hashInSession = ((string)$this->customerSession->getRegistrationPasswordHash() !== '') ? 1 : 0;

            $this->logger->info('[KlarnaFix] AutoLogin decision', [
                'order' => $order->getIncrementId(),
                'quote_id' => (string)$order->getQuoteId(),
                'flag_want' => $flagWant,
                'hash_on_payment' => $hashOnPayment,
                'hash_in_session' => $hashInSession,
            ]);

            $wants = ($flagWant || $hashOnPayment || $hashInSession);

            // 3b) One-time re-check to defeat late seeding (Klarna mobile/new-tab)
            if (!$wants) {
                try {
                    $fresh = $this->orderRepo->get((int)$order->getId());
                    $fp = $fresh->getPayment();
                    $f2 = ($fp && (int)$fp->getAdditionalInformation(self::KEY_WANT) === 1) ? 1 : 0;
                    $h2 = ($fp && (string)$fp->getAdditionalInformation(self::KEY_HASH) !== '') ? 1 : 0;
                    $s2 = ((string)$this->customerSession->getRegistrationPasswordHash() !== '') ? 1 : 0;

                    if ($f2 || $h2 || $s2) {
                        $this->logger->info('[KlarnaFix] AutoLogin re-check flipped to CREATE', [
                            'order' => $order->getIncrementId(),
                            'flag_want' => $f2, 'hash_on_payment' => $h2, 'hash_in_session' => $s2
                        ]);
                        $p = $fp;
                        $wants = true;
                    }
                } catch (\Throwable $e) {
                    // ignore re-check errors
                }
            }

            if (!$wants) {
                $this->logger->info('[KlarnaFix] AutoLoginOnSuccess: guest checkout ? skip account creation', [
                    'email' => $email, 'order' => $order->getIncrementId()
                ]);
                return $result;
            }

            // 4) Create from order (Klarna only)
            try {
                $customer = $this->orderCustomerService->create((int)$order->getId());

                $confirmRequired = (bool)$this->scopeConfig->getValue(
                    'customer/create_account/confirm',
                    ScopeInterface::SCOPE_WEBSITES,
                    $websiteId
                );

                if (!$confirmRequired) {
                    $this->customerSession->setCustomerDataAsLoggedIn($customer);
                    $this->checkoutSession->setData('gbs_autologin_done', 1);
                }

                $this->cleanupRegistrationIntent($order); // clears only for Klarna
                $this->backfillAddressesFromOrder($customer, $order);

                $this->logger->info(
                    '[KlarnaFix] AutoLoginOnSuccess: created account & '.(!$confirmRequired ? 'logged in' : 'waiting for confirmation'),
                    ['customer_id' => (int)$customer->getId(), 'email' => $email, 'order' => $order->getIncrementId()]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('[KlarnaFix] AutoLoginOnSuccess: create-by-order failed', [
                    'order' => $order->getIncrementId(),
                    'e'     => $e->getMessage()
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[KlarnaFix] AutoLoginOnSuccess failed', ['e' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Important: only clear Swissup’s registration hash + our flags for Klarna.
     * Other gateways (e.g. Braintree) may still need the hash after success.
     */
    private function cleanupRegistrationIntent($order): void
    {
        $method = (string)($order->getPayment()?->getMethod() ?? '');
        if ($this->isKlarna($method)) {
            $this->customerSession->unsRegistrationPasswordHash();
            if ($p = $order->getPayment()) {
                $p->unsAdditionalInformation(self::KEY_WANT)
                  ->unsAdditionalInformation(self::KEY_HASH);
            }
        }
    }

    private function backfillAddressesFromOrder(
        \Magento\Customer\Api\Data\CustomerInterface $customer,
        \Magento\Sales\Api\Data\OrderInterface $order
    ): void {
        try {
            $existing = $customer->getAddresses() ?: [];

            $have = [];
            foreach ($existing as $ea) {
                $have[] = $this->norm($ea->getStreet(), $ea->getCity(), $ea->getPostcode(), $ea->getCountryId());
            }

            $billing  = $order->getBillingAddress();
            $shipping = $order->getShippingAddress();
            if (!$billing && !$shipping) {
                return;
            }

            $created = [];

            if ($billing) {
                $sig = $this->norm($billing->getStreet(), $billing->getCity(), $billing->getPostcode(), $billing->getCountryId());
                if (!in_array($sig, $have, true)) {
                    $addr = $this->fromOrderAddress($billing, (int)$customer->getId());
                    $addr->setIsDefaultBilling(true);
                    if (!$shipping || $this->sameAddress($billing, $shipping)) {
                        $addr->setIsDefaultShipping(true);
                    }
                    $created[] = $this->addressRepository->save($addr);
                    $have[] = $sig;
                }
            }

            if ($shipping && (!$billing || !$this->sameAddress($billing, $shipping))) {
                $sig = $this->norm($shipping->getStreet(), $shipping->getCity(), $shipping->getPostcode(), $shipping->getCountryId());
                if (!in_array($sig, $have, true)) {
                    $addr = $this->fromOrderAddress($shipping, (int)$customer->getId());
                    $addr->setIsDefaultShipping(true);
                    if (!$billing) {
                        $addr->setIsDefaultBilling(true);
                    }
                    $created[] = $this->addressRepository->save($addr);
                    $have[] = $sig;
                }
            }

            if ($created) {
                $this->logger->info('[KlarnaFix] Backfilled customer addresses from order', [
                    'customer_id' => (int)$customer->getId(),
                    'order'       => $order->getIncrementId(),
                    'count'       => count($created),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[KlarnaFix] Address backfill failed', ['e' => $e->getMessage()]);
        }
    }

    private function fromOrderAddress(
        OrderAddressInterface $src,
        int $customerId
    ): \Magento\Customer\Api\Data\AddressInterface {
        $addr = $this->addressFactory->create();
        $addr->setId(null); // ensure “new”
        $addr->setCustomerId($customerId);
        $addr->setFirstname((string)$src->getFirstname());
        $addr->setMiddlename((string)$src->getMiddlename());
        $addr->setLastname((string)$src->getLastname());
        $addr->setCompany((string)$src->getCompany());
        $addr->setTelephone((string)$src->getTelephone());
        $addr->setVatId((string)$src->getVatId());

        $addr->setStreet((array)$src->getStreet());
        $addr->setCity((string)$src->getCity());
        $addr->setPostcode((string)$src->getPostcode());
        $addr->setCountryId((string)$src->getCountryId());

        if ($src->getRegionId()) {
            $addr->setRegionId((int)$src->getRegionId());
        }
        if ($src->getRegion()) {
            $region = $this->regionFactory->create();
            $region->setRegion((string)$src->getRegion());
            $addr->setRegion($region);
        }

        return $addr;
    }

    private function sameAddress(OrderAddressInterface $a, OrderAddressInterface $b): bool
    {
        $norm = fn($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
        $streetA = $norm(implode(' ', (array)$a->getStreet()));
        $streetB = $norm(implode(' ', (array)$b->getStreet()));

        return $streetA === $streetB
            && $norm($a->getCity())     === $norm($b->getCity())
            && $norm($a->getPostcode()) === $norm($b->getPostcode())
            && (string)$a->getCountryId() === (string)$b->getCountryId();
    }

    private function norm($street, $city, $postcode, $country): string
    {
        $flat = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', (array)$street))));
        return $flat.'|'.strtolower(trim((string)$city)).'|'.strtolower(trim((string)$postcode)).'|'.strtolower(trim((string)$country));
    }
}
