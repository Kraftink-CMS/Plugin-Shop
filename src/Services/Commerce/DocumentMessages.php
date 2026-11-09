<?php

namespace KraftInk\Plugins\Shop\Services\Commerce;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Settings;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use KraftInk\Plugins\Shop\Jobs\SendDocumentMail;
use KraftInk\Plugins\Shop\Mail\DocumentMail;
use KraftInk\Plugins\Shop\Models\Billing\DocumentMessage;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Models\Guests\Guest;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use Throwable;
use JsonException;

class DocumentMessages
{
    public function enqueue(object $receipt): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!$this->transportEnabled()) {
                return;
            }
            $id = DB::transaction(function () use ($receipt) {
                $receipt = Receipt::query()->where('id', $receipt->id)->lockForUpdate()->first();
                if (!$receipt || !$this->automaticEnabled($receipt)) {
                    return null;
                }
                $snapshot = $receipt->snapshot ?? [];
                $recipient = $this->recipient($receipt);
                if (!empty($snapshot['legacy']) || !$recipient) {
                    return null;
                }
                DocumentMessage::query()->insertOrIgnore([
                    'receipt_id' => $receipt->id,
                    'status' => 'pending',
                    'attempt' => 1,
                    'automatic' => true,
                    'address_hash' => hash('sha256', $recipient['email']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return DocumentMessage::query()->where('receipt_id', $receipt->id)->value('id');
            }, 3);
            if ($id !== null) {
                $this->dispatch($id);
            }
        } finally {
            $operationLease->release();
        }
    }

    public function request(int $receiptId, User $actor, bool $resend = false): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!$actor->hasPermission('shop.manage')) {
                throw new HttpException(403);
            }
            if (!$this->transportEnabled()) {
                throw new HttpException(422, __('shop::commerce.documents.mail.disabled'));
            }
            $id = DB::transaction(function () use ($receiptId, $actor, $resend) {
                $receipt = Receipt::query()->where('id', $receiptId)->lockForUpdate()->first();
                if ($receipt === null) {
                    abort(404);
                }
                $recipient = $this->recipient($receipt);
                if (empty($recipient)) {
                    throw new HttpException(422, __('shop::commerce.documents.mail.unavailable'));
                }
                $existing = DocumentMessage::query()->where('receipt_id', $receiptId)->first();
                if ($existing) {
                    if (in_array($existing->status, ['accepted', 'uncertain', 'cancelled'], true)) {
                        if (!$resend) {
                            throw new HttpException(422, __('shop::commerce.documents.mail.resend_help'));
                        }
                        DocumentMessage::query()
                            ->where('id', $existing->id)
                            ->update([
                                'status' => 'pending',
                                'attempt' => $existing->attempt + 1,
                                'automatic' => false,
                                'address_hash' => hash('sha256', $recipient['email']),
                                'queued_at' => null,
                                'started_at' => null,
                                'accepted_at' => null,
                                'updated_at' => now(),
                            ]);
                        Audit::record(
                            'shop.document.mail_requested_again',
                            [
                                'receipt_id' => $receiptId,
                                'message_id' => $existing->id,
                                'previous_state' => $existing->status,
                                'attempt' => $existing->attempt + 1,
                            ],
                            $actor,
                        );
                    } elseif ($existing->status === 'pending') {
                        DocumentMessage::query()
                            ->where('id', $existing->id)
                            ->update(['automatic' => false, 'updated_at' => now()]);
                    }

                    return $existing->id;
                }
                $id = DocumentMessage::query()->insertGetId([
                    'receipt_id' => $receiptId,
                    'status' => 'pending',
                    'attempt' => 1,
                    'automatic' => false,
                    'address_hash' => hash('sha256', $recipient['email']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record(
                    'shop.document.mail_requested',
                    ['receipt_id' => $receiptId, 'message_id' => $id],
                    $actor,
                );

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
            DocumentMessage::query()
                ->where('status', 'dispatching')
                ->where('started_at', '<', now()->subMinutes(5))
                ->update(['status' => 'uncertain', 'updated_at' => now()]);
            $started = microtime(true);
            foreach (
                DocumentMessage::query()
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
                $message = DocumentMessage::query()->find($id);
                $receipt = $message ? Receipt::query()->find($message->receipt_id) : null;
                if (
                    !$message ||
                    !$receipt ||
                    !$this->transportEnabled() ||
                    ($message->automatic && !$this->automaticEnabled($receipt))
                ) {
                    DocumentMessage::query()
                        ->where('id', $id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'cancelled',
                            'updated_at' => now(),
                        ]);

                    continue;
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
                $message = DocumentMessage::query()->where('id', $id)->lockForUpdate()->first();
                if (!$message || $message->status !== 'pending' || (int) $message->attempt !== $attempt) {
                    return null;
                }
                $receipt = Receipt::query()->find($message->receipt_id);
                $recipient = $receipt ? $this->recipient($receipt) : null;
                if (
                    !$this->transportEnabled() ||
                    !$recipient ||
                    ($message->automatic && !$this->automaticEnabled($receipt)) ||
                    !is_string($message->address_hash) ||
                    !hash_equals($message->address_hash, hash('sha256', $recipient['email']))
                ) {
                    DocumentMessage::query()
                        ->where('id', $id)
                        ->update([
                            'status' => 'cancelled',
                            'updated_at' => now(),
                        ]);

                    return null;
                }
                $snapshot = $receipt->snapshot ?? [];
                DocumentMessage::query()
                    ->where('id', $id)
                    ->update([
                        'status' => 'dispatching',
                        'started_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $recipient + [
                    'number' => $receipt->number,
                    'document_type' => $snapshot['document_type'] ?? $receipt->kind,
                    'body' => $receipt->body,
                ];
            }, 3);
            if (!$payload) {
                return;
            }
            try {
                Mail::to($payload['email'])
                    ->locale($payload['locale'])
                    ->send(new DocumentMail($payload['number'], $payload['document_type'], $payload['body']));
                DocumentMessage::query()
                    ->where('id', $id)
                    ->where('attempt', $attempt)
                    ->where('status', 'dispatching')
                    ->update(['status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now()]);
                Audit::record('shop.document.mail_accepted', ['message_id' => $id]);
            } catch (Throwable) {
                DocumentMessage::query()
                    ->where('id', $id)
                    ->where('attempt', $attempt)
                    ->where('status', 'dispatching')
                    ->update(['status' => 'uncertain', 'updated_at' => now()]);
                Audit::record('shop.document.mail_uncertain', ['message_id' => $id]);
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
                $message = DocumentMessage::query()->where('id', $id)->where('status', 'pending')->first();
                if (!$message || ($message->queued_at && now()->subMinutes(5)->lt($message->queued_at))) {
                    return;
                }
                $attempt = (int) $message->attempt;
                try {
                    SendDocumentMail::dispatch($id, $attempt)->afterCommit();
                    DocumentMessage::query()
                        ->where('id', $id)
                        ->where('attempt', $attempt)
                        ->where('status', 'pending')
                        ->update(['queued_at' => now(), 'updated_at' => now()]);
                } catch (Throwable) {
                    Audit::record('shop.document.mail_queue_failed', ['message_id' => $id]);
                }
            } finally {
                $operationLease->release();
            }
        });
    }

    private function transportEnabled(): bool
    {
        return (bool) app(Settings::class)->get('mail.enabled', false);
    }

    private function automaticEnabled(object $receipt): bool
    {
        if (!app(Settings::class)->get('shop.commerce.email_enabled', true)) {
            return false;
        }
        try {
            $snapshot = $receipt->snapshot ?? [];
        } catch (JsonException) {
            return false;
        }
        $category = $snapshot['mail_category'] ?? ($receipt->kind === 'refund' ? 'refund' : 'purchase');
        $key = match ($category) {
            'purchase' => 'email_purchases',
            'subscription_initial' => 'email_subscription_initial',
            'subscription_renewal' => 'email_subscription_renewals',
            'refund' => 'email_refunds',
            default => null,
        };

        return $key !== null && (bool) app(Settings::class)->get('shop.commerce.'.$key, true);
    }

    private function recipient(object $receipt): ?array
    {
        if (!hash_equals((string) $receipt->body_hash, hash('sha256', $receipt->body))) {
            return null;
        }
        $order = Order::query()->find($receipt->order_id);
        if ($order?->guest_id && !$order->user_id && $order->paid_at) {
            $guest = Guest::verified()->find($order->guest_id);
            if (!$guest) {
                return null;
            }
            try {
                $snapshot = $receipt->snapshot ?? [];
                $email = $guest->email;
            } catch (Throwable) {
                return null;
            }
            if (
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                !hash_equals($snapshot['billing']['email'] ?? '', $email)
            ) {
                return null;
            }

            return ['email' => $email, 'locale' => $snapshot['locale'] ?? $guest->locale];
        }
        $user = $order ? User::query()->find($order->user_id) : null;
        if (
            !$order?->paid_at ||
            !$user ||
            !$user->hasVerifiedEmail() ||
            !is_string($user->email) ||
            $user->anonymized_at ||
            $user->import_locked
        ) {
            return null;
        }
        try {
            $snapshot = $receipt->snapshot ?? [];
        } catch (JsonException) {
            return null;
        }
        $email = $snapshot['billing']['email'] ?? null;
        $email = is_string($email) && $email !== '' ? $email : $user->email;
        if (
            !is_string($email) ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            str_ends_with(strtolower($email), '.invalid') ||
            !hash_equals($email, $user->email)
        ) {
            return null;
        }

        return ['email' => $email, 'locale' => $snapshot['locale'] ?? $user->preferredLocale()];
    }
}
