<?php

namespace KraftInk\Plugins\Shop\View;

final readonly class CheckoutControls
{
    public function __construct(
        public array $policies,
        public array $methods,
        public array $conditions,
        public string $walletBalance,
        public string $walletCurrency,
        public bool $canUseWallet,
        public bool $canUseGiftcard,
        public bool $canUseCoupon,
        public bool $canApplyWallet,
        public bool $canApplyGiftcard,
        public bool $canPay,
        public bool $requiresProvider,
    ) {
    }
}
