<?php
declare(strict_types=1);

namespace GerrardSBS\KlarnaFix\Plugin;

use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
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
        private ScopeConfigInterface $scopeConfig,
        private AddressRepositoryInterface $addressRepository,
        private AddressInterfaceFactory $addressFactory,
		private RegionInterfaceFactory $regionFactory,
        private LoggerInterface $logger
    ) {}

    public function afterExecute(Success $subject, $result)
{
    try {
        // avoid double-run on re-render
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

        // 1) Already linked to a customer? -> log in
        $customerId = (int)$order->getCustomerId();
        if ($customerId > 0) {
            $customer = $this->customerRepo->getById($customerId);
            $this->customerSession->setCustomerDataAsLoggedIn($customer);
            $this->checkoutSession->setData('gbs_autologin_done', 1);
            $this->cleanupRegistrationIntent($order);
            $this->backfillAddressesFromOrder($customer, $order);
            $this->logger->info('[KlarnaFix] AutoLoginOnSuccess: logged customer in', [
                'customer_id' => $customerId,
                'order'       => $order->getIncrementId()
            ]);
            return $result;
        }

        // 2) Existing account by email? -> log in (do this BEFORE wants check)
        if ($email !== '') {
            try {
                $existing = $this->customerRepo->get($email, $websiteId);
                $this->customerSession->setCustomerDataAsLoggedIn($existing);
                $this->checkoutSession->setData('gbs_autologin_done', 1);
                $this->cleanupRegistrationIntent($order);
                $this->backfillAddressesFromOrder($existing, $order);
                $this->logger->info('[KlarnaFix] AutoLoginOnSuccess: fallback login by email', [
                    'email'      => $email,
                    'website_id' => $websiteId,
                    'order'      => $order->getIncrementId()
                ]);
                return $result;
            } catch (\Throwable $e) {
                // no existing account — maybe create below
            }
        }

        // 3) Single place to decide “wants account”
        $p     = $order->getPayment();
        $wants = false;

        // a) flag persisted by PersistRegistrationIntent
        if ($p && (int)$p->getAdditionalInformation(self::KEY_WANT) === 1) {
            $wants = true;
        }

        // b) Swissup hash in session (seeded by CreateAccountSessionSeed)
        if (!$wants && (string)$this->customerSession->getRegistrationPasswordHash() !== '') {
            $wants = true;
        }

        // c) hash copied onto order payment (if present)
        if (!$wants && $p && ((string)$p->getAdditionalInformation(self::KEY_HASH) !== '')) {
            $wants = true;
        }

        if (!$wants) {
            // respect pure guest checkout
            $this->logger->info('[KlarnaFix] AutoLoginOnSuccess: guest checkout → skip account creation', [
                'email' => $email, 'order' => $order->getIncrementId()
            ]);
            return $result;
        }

        // 4) Create customer from order (intent present)
        try {
            $customer = $this->orderCustomerService->create((int)$order->getId());

            $confirmRequired = (bool)$this->scopeConfig->getValue(
                'customer/create_account/confirm',
                \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );

            if (!$confirmRequired) {
                $this->customerSession->setCustomerDataAsLoggedIn($customer);
                $this->checkoutSession->setData('gbs_autologin_done', 1);
            }

            $this->cleanupRegistrationIntent($order);
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


    private function cleanupRegistrationIntent($order): void
    {
        $this->customerSession->unsRegistrationPasswordHash();
        if ($p = $order->getPayment()) {
            $p->unsAdditionalInformation(self::KEY_WANT)
              ->unsAdditionalInformation(self::KEY_HASH);
        }
    }

    private function backfillAddressesFromOrder(
        \Magento\Customer\Api\Data\CustomerInterface $customer,
        \Magento\Sales\Api\Data\OrderInterface $order
    ): void {
        try {
            $existing = $customer->getAddresses() ?: [];
            if (count($existing) > 0) {
                // Still allow dedupe-aware add if none match (in case existing are empty or unrelated)
            }

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
        $addr->setId(null); // ensure “new”                        👈
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
