<?php

namespace KraftInk\Plugins\Shop\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Kraft\System\OperationLock;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Jobs\SendGuestCode;
use KraftInk\Plugins\Shop\Models\Guests\Guest;
use KraftInk\Plugins\Shop\Models\Guests\GuestChallenge;
use KraftInk\Plugins\Shop\Models\Guests\GuestSession;
use Throwable;

class GuestAuth
{
    public function enabled(): bool
    {
        return app(Settings::class)->get('shop.guests.enabled', false) &&
            app(Settings::class)->get('mail.enabled', false);
    }

    public function start(string $email, string $locale, string $browserNonce): string
    {
        return app(OperationLock::class)->shared(function () use ($email, $locale, $browserNonce) {
            if (!$this->enabled()) {
                abort(404);
            }
            $email = mb_strtolower(trim($email));
            $guestValidator = Validator::make(
                ['email' => $email, 'nonce' => $browserNonce],
                ['email' => 'required|email:rfc|max:254', 'nonce' => ['required', 'regex:/^[a-f0-9]{64}$/D']],
            );
            $guestValidator->validate();
            $hash = hash_hmac('sha256', $email, config('app.key'));
            $throttle = 'shop:guest:code:'.$hash;
            if (RateLimiter::tooManyAttempts($throttle, 3)) {
                throw ValidationException::withMessages(['email' => __('shop::messages.guests.rate_limited')]);
            }
            RateLimiter::hit($throttle, 900);
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $id = (string) Str::uuid();
            DB::transaction(function () use ($email, $locale, $browserNonce, $hash, $code, $id) {
                Guest::query()->insertOrIgnore([
                    'email' => Crypt::encryptString($email),
                    'email_hash' => $hash,
                    'locale' => app(LocalePolicy::class)->valid($locale)
                        ? $locale
                        : app(LocalePolicy::class)->defaultLocale(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $guest = Guest::query()->where('email_hash', $hash)->lockForUpdate()->first();
                if (!$guest) {
                    throw new HttpException(422, __('shop::messages.guests.unavailable'));
                }
                $recent = GuestChallenge::query()
                    ->where('guest_id', $guest->id)
                    ->where('created_at', '>', now()->subMinute())
                    ->exists();
                if ($recent) {
                    throw ValidationException::withMessages(['email' => __('shop::messages.guests.rate_limited')]);
                }
                GuestChallenge::query()
                    ->where('guest_id', $guest->id)
                    ->whereNull('used_at')
                    ->where('expires_at', '>', now())
                    ->update(['expires_at' => now()]);
                GuestChallenge::query()->insert([
                    'id' => $id,
                    'guest_id' => $guest->id,
                    'code_hash' => $this->codeHash($id, $code),
                    'browser_hash' => hash('sha256', $browserNonce),
                    'expires_at' => now()->addMinutes(10),
                    'created_at' => now(),
                ]);
            }, 3);
            try {
                SendGuestCode::dispatch($id, $code)->afterCommit();
            } catch (Throwable) {
                GuestChallenge::query()
                    ->where('id', $id)
                    ->update(['expires_at' => now()]);
                Audit::record('shop.guest.code_queue_failed', ['challenge_id' => $id]);
                throw ValidationException::withMessages(['email' => __('shop::messages.guests.mail_unavailable')]);
            }

            return $id;
        });
    }

    public function verify(string $id, string $code, string $browserNonce): string
    {
        return app(OperationLock::class)->shared(function () use ($id, $code, $browserNonce) {
            if (!$this->enabled()) {
                abort(404);
            }
            $guestId = GuestChallenge::query()->where('id', $id)->value('guest_id');
            $token = DB::transaction(function () use ($guestId, $id, $code, $browserNonce) {
                $guest = $guestId ? Guest::query()->where('id', $guestId)->lockForUpdate()->first() : null;
                $challenge = $guest ? GuestChallenge::query()->where('id', $id)->lockForUpdate()->first() : null;
                if (
                    !$guest ||
                    !$challenge ||
                    $challenge->used_at ||
                    now()->gte($challenge->expires_at) ||
                    $challenge->attempts >= 5 ||
                    !hash_equals($challenge->browser_hash, hash('sha256', $browserNonce))
                ) {
                    return null;
                }
                GuestChallenge::query()->where('id', $id)->increment('attempts');
                if (
                    !preg_match('/^[0-9]{8}$/D', $code) ||
                    !hash_equals($challenge->code_hash, $this->codeHash($id, $code))
                ) {
                    return null;
                }
                GuestChallenge::query()
                    ->where('id', $id)
                    ->update(['used_at' => now()]);
                Guest::query()
                    ->where('id', $guestId)
                    ->update(['verified_at' => now(), 'updated_at' => now()]);
                $token = bin2hex(random_bytes(32));
                GuestSession::query()->insert([
                    'id' => (string) Str::uuid(),
                    'guest_id' => $guestId,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addDay(),
                    'created_at' => now(),
                ]);

                return $token;
            }, 3);
            if (!$token) {
                throw ValidationException::withMessages(['code' => __('shop::messages.guests.invalid_code')]);
            }

            return $token;
        });
    }

    public function guest(?string $token): ?Guest
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        $session = GuestSession::usable()->where('token_hash', hash('sha256', $token))->with('guest')->first();
        $guest = $session?->guest;

        if ($guest) {
            $guest->session_id = $session->id;
            $guest->session_created_at = $session->created_at;
            $guest->email_address = $guest->email;
        }

        return $guest;
    }

    public function buyer(int $id, bool $lock = false): Guest
    {
        $guest = Guest::query()
            ->where('id', $id)
            ->verified()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
        if ($guest === null) {
            abort(404);
        }
        $guest->email_address = $guest->email;

        return $guest;
    }

    public function revoke(string $token): void
    {
        app(OperationLock::class)->shared(
            fn () => GuestSession::query()
                ->where('token_hash', hash('sha256', $token))
                ->update(['revoked_at' => now()]),
        );
    }

    public function codeHash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.':'.$code, config('app.key'));
    }
}
