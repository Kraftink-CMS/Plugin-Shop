<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\GiftCards;

use Illuminate\Support\Collection;
use App\Kraft\Settings;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardMessage;
use KraftInk\Plugins\Shop\Services\GiftCards\CardMessages;
use KraftInk\Plugins\Shop\Services\GiftCards\CardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class CardController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $cards = GiftCard::query()
            ->visibleTo($user)
            ->with(['issuance', 'sourceItem.order'])
            ->orderByDesc('id')
            ->paginate(25);
        /** @var Collection<string, GiftCardMessage> $messages */
        $messages = GiftCardMessage::query()
            ->where('user_id', $user->id)
            ->whereIn('giftcard_id', $cards->pluck('id'))
            ->get()
            ->keyBy(fn ($message) => $message->giftcard_id.':'.$message->event);
        $mailEnabled = app(Settings::class)->get('mail.enabled', false) && $user->hasVerifiedEmail();
        $cards->through(function ($card) use ($user, $messages, $mailEnabled) {
            $owned = $card->owner_id === $user->id;
            $buyer = $card->sourceItem?->order?->user_id === $user->id;

            return (object) [
                'id' => $card->id,
                'last_four' => $card->last_four,
                'balance_minor' => $owned || !$card->owner_id ? $card->balance_minor : null,
                'currency' => $card->currency,
                'expires_at' => $card->expires_at,
                'enabled' => $card->enabled,
                'claimed_at' => $card->claimed_at,
                'owner_id' => $owned ? $user->id : null,
                'owned' => $owned,
                'face_minor' => $card->issuance?->face_minor,
                'code' => $owned && $card->secret ? $card->secret : null,
                'claim_url' => $buyer && !$card->owner_id && $card->claim_secret && $card->enabled
                        ? route('plugins.shop.public.giftcards.claim', $card->claim_secret)
                        : null,
                'buyer' => $buyer,
                'can_email' => $mailEnabled && $card->enabled && ($owned || ($buyer && !$card->owner_id)),
                'mail_status' => $messages->get($card->id.':'.($owned ? 'code' : 'claim'))?->status,
            ];
        });

        return $this->privateResponse('shop::default.giftcards.index', ['cards' => $cards]);
    }

    public function claim(Request $request, string $token, CardService $cards): RedirectResponse|Response
    {
        $card = $cards->findClaim($token);
        if ($card->owner_id) {
            if ($request->user()?->id !== $card->owner_id) {
                abort(404);
            }

            return redirect()->route('plugins.shop.public.giftcards.index');
        }
        if ($request->user() && $card->recipient_id && $card->recipient_id !== $request->user()->id) {
            abort(404);
        }
        if (!$request->user()) {
            $request->session()->put('url.intended', route('plugins.shop.public.giftcards.claim', $token, false));
        }

        return $this->privateResponse('shop::default.giftcards.claim', [
            'token' => $token,
            'card' => (object) [
                'balance_minor' => $card->balance_minor,
                'currency' => $card->currency,
                'expires_at' => $card->expires_at,
                'assigned' => $card->recipient_id !== null,
                'available' => $card->isSpendable(),
            ],
        ]);
    }

    public function redeem(Request $request, string $token, CardService $cards): RedirectResponse
    {
        $request->validate(['accept_card' => 'accepted']);
        $cards->claim($token, $request->user());

        return redirect()
            ->route('plugins.shop.public.giftcards.index')
            ->with('status', __('shop::commerce.giftcards.claimed'));
    }

    public function email(Request $request, int $id, CardMessages $messages): RedirectResponse
    {
        $request->validate(['email_consent' => 'accepted', 'confirm_resend' => 'sometimes|accepted']);
        $messages->request($id, $request->user(), $request->boolean('confirm_resend'));

        return redirect()
            ->route('plugins.shop.public.giftcards.index')
            ->with('status', __('shop::commerce.giftcards.mail.queued'));
    }

    private function privateResponse(string $view, array $data): Response
    {
        return response()
            ->view($view, $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
