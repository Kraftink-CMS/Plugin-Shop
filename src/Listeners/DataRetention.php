<?php

namespace KraftInk\Plugins\Shop\Listeners;

use App\Events\PruningData;
use App\Kraft\System\RetentionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KraftInk\Plugins\Shop\Models\Guests\GuestChallenge;
use KraftInk\Plugins\Shop\Models\Guests\GuestSession;
use Illuminate\Database\Eloquent\Model;

class DataRetention
{
    public function prune(PruningData $event): void
    {
        $cutoff = now()->subDays(app(RetentionPolicy::class)->days('temporary'));
        foreach ([GuestChallenge::class, GuestSession::class] as $model) {
            /** @var class-string<Model> $model */
            $table = new $model()->getTable();
            if (!Schema::hasTable($table)) {
                continue;
            }
            $query = $model::query()->where('expires_at', '<', $cutoff);
            if ($event->dryRun) {
                $event->add(str_replace('__', '.', $table), $query->count());

                continue;
            }
            $count = 0;
            do {
                $deleted = DB::transaction(function () use ($model, $cutoff): int {
                    $ids = $model::query()
                        ->where('expires_at', '<', $cutoff)
                        ->orderBy('id')
                        ->limit(500)
                        ->lockForUpdate()
                        ->pluck('id');

                    return $model::query()->whereIn('id', $ids)->where('expires_at', '<', $cutoff)->delete();
                }, 3);
                $count += $deleted;
            } while ($deleted === 500);
            $event->add(str_replace('__', '.', $table), $count);
        }
    }
}
