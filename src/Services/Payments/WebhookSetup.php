<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebhookSetup
{
    public const array FIELDS = [
        'stripe' => 'webhook_secret',
        'paypal' => 'webhook_id',
    ];

    private const string PENDING_CREDENTIALS = 'pending_credentials';

    public function __construct(private GatewayRegistry $registry) {}

    public function state(string $provider): array
    {
        $pending = $this->pendingCredentials($provider);
        $credentials = $pending ?? $this->activeCredentials($provider);

        return [
            'saved' => $this->hasCredentials($provider, $credentials),
            'pending' => $pending !== null,
            'sandbox' => $credentials['sandbox'] ?? '1',
        ];
    }

    public function saveCredentials(string $provider, #[SensitiveParameter] array $submitted): void
    {
        $active = $this->activeCredentials($provider);
        $pending = $this->pendingCredentials($provider);
        $current = $pending ?? $active;
        $credentials = ['secret' => $submitted['secret'] ?? $current['secret']];
        if ($provider === 'paypal') {
            $credentials['client_id'] = $submitted['client_id'] ?? $current['client_id'];
            $credentials['sandbox'] = (string) (int) $submitted['sandbox'];
            if (
                $credentials['sandbox'] !== $current['sandbox'] &&
                $current['client_id'] !== '' &&
                ($credentials['client_id'] === $current['client_id'] || !isset($submitted['secret']))
            ) {
                $this->invalid($provider, 'client_id', 'paypal_environment_credentials');
            }
            if ($credentials['client_id'] !== $current['client_id'] && !isset($submitted['secret'])) {
                $this->invalid($provider, 'secret', 'paypal_secret_required');
            }
        }
        $this->validateCredentials($provider, $credentials['secret'], $credentials['client_id'] ?? '');
        $this->verifyCredentials($provider, $credentials);

        if ($credentials !== $current) {
            $this->registry->set(
                $provider,
                self::PENDING_CREDENTIALS,
                $credentials === $active ? '' : json_encode($credentials, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function saveWebhook(string $provider, string $mode, #[SensitiveParameter] ?string $value): void
    {
        $active = $this->activeCredentials($provider);
        $pending = $this->pendingCredentials($provider);
        $credentials = $pending ?? $active;
        if (!$this->hasCredentials($provider, $credentials)) {
            $this->invalid($provider, 'webhook', 'credentials_required');
        }
        $webhook = $mode === 'automatic'
            ? $this->provision($provider, $credentials)
            : $this->manualWebhook($provider, $value, $credentials, $active);

        DB::transaction(function () use ($provider, $credentials, $pending, $webhook) {
            foreach ($webhook as $key => $value) {
                $this->registry->set($provider, $key, $value);
            }
            if ($pending !== null) {
                foreach ($credentials as $key => $value) {
                    $this->registry->set($provider, $key, $value);
                }
                $this->registry->set($provider, self::PENDING_CREDENTIALS, '');
            }
        });
    }

    private function activeCredentials(string $provider): array
    {
        $credentials = ['secret' => $this->registry->setting($provider, 'secret')];
        if ($provider === 'paypal') {
            $credentials['client_id'] = $this->registry->setting($provider, 'client_id');
            $credentials['sandbox'] = $this->registry->setting($provider, 'sandbox', '1');
        }

        return $credentials;
    }

    private function pendingCredentials(string $provider): ?array
    {
        $value = $this->registry->setting($provider, self::PENDING_CREDENTIALS);

        return $value === '' ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function hasCredentials(string $provider, #[SensitiveParameter] array $credentials): bool
    {
        return $credentials['secret'] !== '' && ($provider !== 'paypal' || $credentials['client_id'] !== '');
    }

    private function verifyCredentials(string $provider, #[SensitiveParameter] array $credentials): void
    {
        try {
            if ($provider === 'stripe') {
                app(StripeService::class)->validateWebhookCredentials($credentials['secret']);
            } else {
                app(PayPalService::class)->validateWebhookCredentials(
                    $credentials['client_id'],
                    $credentials['secret'],
                    $credentials['sandbox'] === '1',
                );
            }
        } catch (ApiErrorException | RequestException $exception) {
            $status = $exception instanceof ApiErrorException ? $exception->getHttpStatus() : $exception->response->status();
            $this->invalid($provider, 'secret', in_array($status, [401, 403], true) ? $provider.'_credentials_rejected' : 'credentials_check_failed');
        } catch (ConnectionException | HttpException | UnexpectedValueException) {
            $this->invalid($provider, 'secret', 'credentials_check_failed');
        }
    }

    private function provision(string $provider, #[SensitiveParameter] array $credentials): array
    {
        $url = route('plugins.shop.webhooks.' . $provider . '.handle');
        $this->validateUrl($provider, $url);

        return $this->configure(
            $provider,
            $url,
            $credentials['secret'],
            $credentials['client_id'] ?? '',
            ($credentials['sandbox'] ?? '0') === '1',
        );
    }

    private function manualWebhook(
        string $provider,
        #[SensitiveParameter] ?string $value,
        #[SensitiveParameter] array $credentials,
        #[SensitiveParameter] array $active,
    ): array {
        $field = self::FIELDS[$provider];
        $stored = $this->registry->setting($provider, $field);
        if ($provider === 'stripe') {
            if ($value === null || !str_starts_with($value, 'whsec_') || $value === 'whsec_') {
                $this->invalid($provider, $field, 'stripe_webhook_required');
            }

            return $value === $stored ? [$field => $value] : [$field => $value, 'webhook_id' => ''];
        }

        if ($value === null || $value === '') {
            $this->invalid($provider, $field, 'paypal_webhook_required');
        }
        if ($value === $stored && ($credentials['client_id'] !== $active['client_id'] || $credentials['sandbox'] !== $active['sandbox'])) {
            $this->invalid($provider, $field, 'credentials_changed');
        }

        return [$field => $value];
    }

    private function validateUrl(string $provider, string $url): void
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[].'));
        $publicHost = filter_var($host, FILTER_VALIDATE_IP)
            ? filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            : str_contains($host, '.') && !preg_match('/\.(localhost|local|test|internal|invalid)$/', $host);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$publicHost) {
            $this->invalid($provider, 'webhook', 'https_required');
        }
        if ($provider === 'paypal' && (parse_url($url, PHP_URL_PORT) ?? 443) !== 443) {
            $this->invalid($provider, 'webhook', 'paypal_port_required');
        }
    }

    private function validateCredentials(string $provider, #[SensitiveParameter] string $secret, string $clientId): void
    {
        if ($provider === 'stripe' && !preg_match('/\A(?:rk|sk)_(?:test|live)_[A-Za-z0-9]+\z/', $secret)) {
            $this->invalid($provider, 'secret', 'stripe_key_required');
        }
        if ($provider === 'paypal' && $clientId === '') {
            $this->invalid($provider, 'client_id', 'paypal_client_required');
        }
        if ($provider === 'paypal' && $secret === '') {
            $this->invalid($provider, 'secret', 'paypal_secret_required');
        }
    }

    private function configure(
        string $provider,
        string $url,
        #[SensitiveParameter] string $secret,
        string $clientId,
        bool $sandbox,
    ): array {
        try {
            if ($provider === 'stripe') {
                $endpoint = app(StripeService::class)->configureWebhook(
                    $url,
                    $secret,
                    $this->registry->setting('stripe', 'webhook_id'),
                    $this->registry->setting('stripe', 'webhook_secret'),
                );

                return ['webhook_id' => $endpoint['id'], 'webhook_secret' => $endpoint['secret']];
            }

            return ['webhook_id' => app(PayPalService::class)->configureWebhook($url, $clientId, $secret, $sandbox)];
        } catch (ApiErrorException | RequestException $exception) {
            $status =
                $exception instanceof ApiErrorException ? $exception->getHttpStatus() : $exception->response->status();
            if (in_array($status, [401, 403], true)) {
                $this->invalid($provider, 'webhook', $provider . '_access');
            }
            $this->invalid($provider, 'webhook', 'request_failed');
        } catch (HttpException $exception) {
            if ($provider === 'stripe' && $exception->getStatusCode() === 409) {
                $this->invalid($provider, self::FIELDS[$provider], 'stripe_existing');
            }
            $this->invalid($provider, 'webhook', 'request_failed');
        } catch (ConnectionException | UnexpectedValueException) {
            $this->invalid($provider, 'webhook', 'request_failed');
        }
    }

    private function invalid(string $provider, string $field, string $message): never
    {
        throw ValidationException::withMessages([
            'gateways.' . $provider . '.' . $field => __('shop::commerce.payments.webhook_setup.' . $message),
        ]);
    }
}
