<?php

namespace KraftInk\Plugins\Shop\Console\Commands;

use App\Kraft\System\OperationLock;
use Illuminate\Console\Command;
use KraftInk\Plugins\Shop\Services\Commerce\DocumentMessages;
use KraftInk\Plugins\Shop\Services\GiftCards\CardMessages;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use Throwable;

class ProcessTasks extends Command
{
    protected $signature = 'shop:process';

    protected $description = 'Recover pending shop operations, messages and expired reservations';

    public function handle(OrderService $orders, DocumentMessages $documents, CardMessages $cards): int
    {
        return app(OperationLock::class)->shared(function () use ($orders, $documents, $cards): int {
            $status = self::SUCCESS;
            $tasks = [
                fn () => $orders->recoverProcessing(25),
                $orders->expire(...),
                fn () => $documents->dispatchPending(25),
                fn () => $cards->dispatchPending(25),
            ];
            foreach ($tasks as $task) {
                try {
                    $task();
                } catch (Throwable $exception) {
                    report($exception);
                    $status = self::FAILURE;
                }
            }

            return $status;
        });
    }
}
