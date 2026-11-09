<?php

namespace KraftInk\Plugins\Shop\Services\GiftCards;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Settings;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use KraftInk\Plugins\Shop\Jobs\SendCardMail;
use KraftInk\Plugins\Shop\Mail\CardMail;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardMessage;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use Throwable;

class CardMessages
{
    public function request(int $cardId, User $user, bool $resend = false): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!app(Settings::class)->get('mail.enabled', false)) {
                throw new HttpException(422, __('shop::commerce.giftcards.mail.disabled'));
            }
            $id = DB::transaction(function () use ($cardId, $user, $resend) {
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $card = GiftCard::query()->where('id', $cardId)->lockForUpdate()->first();
                if (!($card && $this->canReceive($card, $user))) {
                    abort(404);
                }
                $event = $card->owner_id === $user->id ? 'code' : 'claim';
                $existing = GiftCardMessage::query()
                    ->where('giftcard_id', $cardId)
                    ->where('user_id', $user->id)
                    ->where('event', $event)
                    ->first();
                if ($existing) {
                    if (in_array($existing->status, ['accepted', 'uncertain', 'cancelled'], true)) {
                        if (!$resend) {
                            throw new HttpException(422, __('shop::commerce.giftcards.mail.resend_help'));
                        }
                        GiftCardMessage::query()
                            ->where('id', $existing->id)
                            ->update([
                                'status' => 'pending',
                                'attempt' => $existing->attempt + 1,
                                'address_hash' => hash('sha256', $user->email),
                                'consented_at' => now(),
                                'queued_at' => null,
                                'started_at' => null,
                                'accepted_at' => null,
                                'updated_at' => now(),
                            ]);
                        Audit::record(
                            'shop.giftcard.mail_requested_again',
                            [
                                'giftcard_id' => $cardId,
                                'message_id' => $existing->id,
                                'previous_state' => $existing->status,
                                'attempt' => $existing->attempt + 1,
                            ],
                            $user,
                        );
                    }

                    return $existing->id;
                }
                $id = GiftCardMessage::query()->insertGetId([
                    'giftcard_id' => $cardId,
                    'user_id' => $user->id,
                    'event' => $event,
                    'address_hash' => hash('sha256', $user->email),
                    'status' => 'pending',
                    'consented_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record('shop.giftcard.mail_requested', ['giftcard_id' => $cardId, 'message_id' => $id], $user);

                return $id;
            }, 3);
            $this->dispatch($id);
        } finally {
            $operationLease->release();
        }
    }

    public function dispatchPending(int $limit = 100): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!app(Settings::class)->get('mail.enabled', false)) {
                return;
            }
            GiftCardMessage::query()
                ->where('status', 'dispatching')
                ->where('started_at', '<', now()->subMinutes(5))
                ->update(['status' => 'uncertain', 'updated_at' => now()]);
            $started = microtime(true);
            foreach (
                GiftCardMessage::query()
                    ->where('status', 'pending')
                    ->where(
                        fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)),
                    )
                    ->orderBy('id')
                    ->limit(max(1, min(100, $limit)))
                    ->pluck('id') as $id
            ) {
                if (microtime(true) - $started >= 10) {
                    break;
                }
                $this->dispatch($id);
            }
        } finally {
            $operationLease->release();
        }
    }

    public function send(int $id, int $attempt): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!in_array('shop', app(ExtensionManager::class)->enabled(), true)) {
                return;
            }
            $payload = DB::transaction(function () use ($id, $attempt) {
                $message = GiftCardMessage::query()->where('id', $id)->lockForUpdate()->first();
                if (!$message || $message->status !== 'pending' || $message->attempt !== $attempt) {
                    return null;
                }
                $user = User::query()->find($message->user_id);
                $card = GiftCard::query()->find($message->giftcard_id);
                $event = $card?->owner_id === $user?->id ? 'code' : 'claim';
                if (
                    !app(Settings::class)->get('mail.enabled', false) ||
                    !$message->consented_at ||
                    !$card ||
                    !$user ||
                    !hash_equals($message->address_hash, hash('sha256', $user->email)) ||
                    $event !== $message->event ||
                    !$this->canReceive($card, $user)
                ) {
                    GiftCardMessage::query()
                        ->where('id', $id)
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);

                    return null;
                }
                $secret = $event === 'code' ? $card->secret : $card->claim_secret;
                $value = $event === 'code' ? $secret : route('plugins.shop.public.giftcards.claim', $secret);
                GiftCardMessage::query()
                    ->where('id', $id)
                    ->update(['status' => 'dispatching', 'started_at' => now(), 'updated_at' => now()]);

                return [
                    'email' => $user->email,
                    'locale' => $user->preferredLocale(),
                    'event' => $event,
                    'value' => $value,
                    'last_four' => $card->last_four,
                ];
            }, 3);
            if (!$payload) {
                return;
            }
            try {
                Mail::to($payload['email'])
                    ->locale($payload['locale'])
                    ->send(new CardMail($payload['event'], $payload['value'], $payload['last_four']));
                GiftCardMessage::query()
                    ->where('id', $id)
                    ->where('attempt', $attempt)
                    ->where('status', 'dispatching')
                    ->update(['status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now()]);
                Audit::record('shop.giftcard.mail_accepted', ['message_id' => $id]);
            } catch (Throwable) {
                GiftCardMessage::query()
                    ->where('id', $id)
                    ->where('attempt', $attempt)
                    ->where('status', 'dispatching')
                    ->update(['status' => 'uncertain', 'updated_at' => now()]);
                Audit::record('shop.giftcard.mail_uncertain', ['message_id' => $id]);
            }
        } finally {
            $operationLease->release();
        }
    }

    private function dispatch(int $id): void
    {
        DB::afterCommit(function () use ($id) {
            $operationLease = app(OperationLock::class)->acquireShared();
            try {
                $message = GiftCardMessage::query()->where('id', $id)->where('status', 'pending')->first();
                if (!$message || ($message->queued_at && now()->subMinutes(5)->lt($message->queued_at))) {
                    return;
                }
                $attempt = (int) $message->attempt;
                try {
                    SendCardMail::dispatch($id, $attempt)->afterCommit();
                    GiftCardMessage::query()
                        ->where('id', $id)
                        ->where('attempt', $attempt)
                        ->where('status', 'pending')
                        ->update(['queued_at' => now(), 'updated_at' => now()]);
                } catch (Throwable) {
                    Audit::record('shop.giftcard.mail_queue_failed', ['message_id' => $id]);
                }
            } finally {
                $operationLease->release();
            }
        });
    }

    private function canReceive(object $card, User $user): bool
    {
        if (
            !$user->hasVerifiedEmail() ||
            !filter_var($user->email, FILTER_VALIDATE_EMAIL) ||
            str_ends_with($user->email, '.invalid') ||
            $user->anonymized_at ||
            $user->import_locked ||
            $user->isBanned() ||
            !$card->enabled ||
            $card->revoked_at ||
            ($card->expires_at && now()->gte($card->expires_at))
        ) {
            return false;
        }
        if ($card->owner_id) {
            return $card->owner_id === $user->id && $card->secret !== null;
        }
        $order = Order::query()
            ->join('shop__order_items', 'shop__order_items.order_id', '=', 'shop__orders.id')
            ->where('shop__order_items.id', $card->source_item_id)
            ->select('shop__orders.*')
            ->first();

        return $order && $order->user_id === $user->id && $card->claim_secret && $order->isUsable();
    }
}
