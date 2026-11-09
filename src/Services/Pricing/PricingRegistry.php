<?php

namespace KraftInk\Plugins\Shop\Services\Pricing;

use InvalidArgumentException;
use KraftInk\Plugins\Shop\Contracts\UpgradePricing;

class PricingRegistry
{
    private array $strategies = [];

    public function register(string $id, string $class): void
    {
        if (
            !preg_match('/^[a-z][a-z0-9-]{1,31}$/', $id) ||
            !is_subclass_of($class, UpgradePricing::class) ||
            (isset($this->strategies[$id]) && $this->strategies[$id] !== $class)
        ) {
            throw new InvalidArgumentException('Invalid upgrade pricing registration.');
        }
        $this->strategies[$id] = $class;
    }

    public function get(string $id): UpgradePricing
    {
        $strategy = app(
            $this->strategies[$id] ?? throw new InvalidArgumentException('Upgrade pricing is unavailable.'),
        );
        if ($strategy->id() !== $id) {
            throw new InvalidArgumentException('Upgrade pricing identifier mismatch.');
        }

        return $strategy;
    }

    public function all(): array
    {
        return array_map(
            fn ($id) => $this->get($id),
            array_combine(array_keys($this->strategies), array_keys($this->strategies)),
        );
    }
}
