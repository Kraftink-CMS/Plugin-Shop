<?php

namespace KraftInk\Plugins\Shop\View;

use App\Kraft\Features;
use App\Models\Accounts\User;
use Illuminate\Contracts\View\View;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;

final readonly class AccountPanel
{
    public function __construct(public bool $canViewOrders, public bool $canViewGiftCards)
    {
    }

    public static function forUser(?User $user): self
    {
        if (!$user) {
            return new self(false, false);
        }

        return new self(
            canViewOrders: app(Features::class)->allows('shop', $user) ||
                Order::query()->where('user_id', $user->id)->exists() ||
                OrderItem::query()->where('recipient_id', $user->id)->exists() ||
                Subscription::query()->where('user_id', $user->id)->exists(),
            canViewGiftCards: GiftCard::query()->visibleTo($user)->exists(),
        );
    }

    public function isAvailable(): bool
    {
        return $this->canViewOrders || $this->canViewGiftCards;
    }

    public static function section(array $context): View|string
    {
        $user = $context['user'] ?? null;
        if (!($user instanceof User) || $user->id !== auth()->id()) {
            return '';
        }
        $panel = self::forUser($user);
        if (!$panel->isAvailable()) {
            return '';
        }

        return view('shop::default.account.section', ['shopAccount' => $panel]);
    }
}
