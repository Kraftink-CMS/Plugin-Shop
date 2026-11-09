<?php

namespace KraftInk\Plugins\Shop\Jobs;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\System\OperationLock;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use KraftInk\Plugins\Shop\Mail\GuestCodeMail;
use KraftInk\Plugins\Shop\Models\Guests\Guest;
use KraftInk\Plugins\Shop\Models\Guests\GuestChallenge;
use KraftInk\Plugins\Shop\Services\GuestAuth;

class SendGuestCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $challengeId, public string $code)
    {
    }

    public function backoff(): array
    {
        return [60, 120];
    }

    public function handle(GuestAuth $auth): void
    {
        app(OperationLock::class)->shared(function () use ($auth) {
            if (!$auth->enabled() || !in_array('shop', app(ExtensionManager::class)->enabled(), true)) {
                return;
            }
            $challenge = GuestChallenge::query()->where('id', $this->challengeId)->first();
            if (
                !$challenge ||
                $challenge->used_at ||
                $challenge->attempts >= 5 ||
                now()->gte($challenge->expires_at) ||
                !hash_equals($challenge->code_hash, $auth->codeHash($this->challengeId, $this->code))
            ) {
                return;
            }
            $guest = Guest::query()->find($challenge->guest_id);
            if (!$guest) {
                return;
            }
            Mail::to($guest->email)->locale($guest->locale)->send(new GuestCodeMail($this->code));
        });
    }
}
