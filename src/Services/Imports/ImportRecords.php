<?php

namespace KraftInk\Plugins\Shop\Services\Imports;

use App\Kraft\Migration\ImportContext;
use Illuminate\Support\Str;
use RuntimeException;

abstract class ImportRecords
{
    private array $sources = [];

    protected function historicalOrder(ImportContext $context, array $record): array
    {
        $result = $this->insert($context, 'shop__orders', [
            'reference' => (string) Str::uuid(),
            'checkout_key' => (string) Str::uuid(),
            'user_id' => $record['user_id'],
            'status' => $record['status'],
            'provider' => 'legacy',
            'payment_reference' => 'import:'.substr(hash('sha256', $context->runId.':payment:'.$record['id']), 0, 40),
            'payment_kind' => 'historical',
            'currency' => $record['currency'],
            'subtotal_minor' => $record['amount_minor'],
            'total_minor' => $record['amount_minor'],
            'refunded_minor' => $record['status'] === 'refunded' ? $record['amount_minor'] : 0,
            'financial_review' => true,
            'auto_expire' => false,
            'expires_at' => $record['created_at'],
            'paid_at' => in_array($record['status'], ['paid', 'refunded'], true) ? $record['updated_at'] : null,
            'released_at' => now(),
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
        ]);

        return $result + ['metadata' => ['source_status' => $record['source_status']]];
    }

    protected function commands(array $commands, array $replace, array &$review): ?string
    {
        $result = [];
        foreach ($commands as $command) {
            if (!is_string($command) || strlen($command) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $command)) {
                $review[] = 'invalid_commands';

                continue;
            }
            $command = strtr(trim($command), $replace);
            preg_match_all('/\{([^}]+)\}/', $command, $tokens);
            if (
                array_filter(
                    $tokens[1],
                    fn ($token) => !in_array($token, ['player', 'uuid', 'steam_id', 'steam_id_32'], true) &&
                        !preg_match('/^var:[a-z][a-z0-9_]{0,31}$/D', $token),
                )
            ) {
                $review[] = 'command_placeholders';

                continue;
            }
            if ($command !== '') {
                $result[] = $command;
            }
        }

        return $result === [] ? null : json_encode($result, JSON_THROW_ON_ERROR);
    }

    protected function usage(
        ImportContext $context,
        int $coupon,
        ?int $user,
        int $uses,
        string $key,
        mixed $date = null,
    ): void {
        $context->target->table('shop__coupon_usage_baselines')->insertOrIgnore([
            'coupon_id' => $coupon,
            'user_id' => $user,
            'uses' => $this->integer($uses),
            'source_key' => hash('sha256', $context->runId.':'.$key),
            'occurred_at' => $date,
        ]);
    }

    protected function couponCode(string $code, ImportContext $context): string
    {
        $code = strtoupper(trim($code));
        if (
            $code === '' ||
            strlen($code) > 60 ||
            $context->target->table('shop__coupons')->where('code', $code)->exists()
        ) {
            throw new RuntimeException('An imported shop coupon code is invalid or already exists.');
        }

        return $code;
    }

    protected function rows(ImportContext $context, string $table): array
    {
        return $this->sources[$context->runId][$table] ??= iterator_to_array(
            (function () use ($context, $table) {
                yield from $context->sourceRows($table) ?? [];
            })(),
            false,
        );
    }

    protected function row(ImportContext $context, string $table, mixed $id): ?array
    {
        foreach ($this->rows($context, $table) as $row) {
            if ((string) ($row['id'] ?? '') === (string) $id) {
                return $row;
            }
        }

        return null;
    }

    protected function relation(ImportContext $context, string $table, mixed $id, array &$review): ?int
    {
        if (!$id) {
            return null;
        }
        $mapped = $context->mapped($table, $id);
        if ($mapped === null) {
            $review[] = 'missing_relation';
        }

        return $mapped;
    }

    protected function minor(mixed $value): int
    {
        $value = (string) $value;
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            throw new RuntimeException('Unsupported imported shop money precision.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $this->integer((int) $whole * 100 + (int) str_pad($fraction, 2, '0'));
    }

    protected function integer(mixed $value): int
    {
        if (
            (!is_int($value) && (!is_string($value) || !ctype_digit($value))) ||
            (int) $value < 0 ||
            (int) $value > 4294967295
        ) {
            throw new RuntimeException('Invalid imported shop integer.');
        }

        return (int) $value;
    }

    protected function limit(mixed $value): ?int
    {
        return $value === null || $value === '' || (string) $value === '0' ? null : $this->integer($value);
    }

    protected function currency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', strtoupper($value))) {
            throw new RuntimeException('Invalid imported shop currency.');
        }

        return strtoupper($value);
    }

    protected function insert(ImportContext $context, string $table, array $values): array
    {
        return ['target_table' => $table, 'target_id' => $context->insert($table, $values)];
    }

    protected function archive(ImportContext $context, string $table, mixed $id, string $reason): array
    {
        $this->notice($context, 'archived', $table, $id, $reason);

        return [
            'target_table' => null,
            'target_id' => null,
            'metadata' => ['archive_only' => true, 'reason' => $reason],
        ];
    }

    protected function notice(
        ImportContext $context,
        string $kind,
        string $table,
        mixed $id,
        string $reason,
        array $details = [],
    ): void {
        if (isset($details['path']) && is_string($details['path'])) {
            $path = $details['path'];
            if (str_starts_with($path, '//') || preg_match('/^https?:\/\//i', $path)) {
                $parts = parse_url(str_starts_with($path, '//') ? 'https:'.$path : $path);
                $path = isset($parts['host'])
                    ? ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '/')
                    : '';
            } else {
                $path = explode('?', explode('#', $path, 2)[0], 2)[0];
            }
            $details['path'] = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $path), 0, 1000);
        }
        $context->report($kind, ['plugin' => 'shop', 'table' => $table, 'id' => $id, 'reason' => $reason] + $details);
    }
}
