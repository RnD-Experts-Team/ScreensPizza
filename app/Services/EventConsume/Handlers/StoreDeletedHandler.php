<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\Log;

class StoreDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storeId = (int) (
            data_get($event, 'data.store_id')
                ?? data_get($event, 'store_id')
                ?? data_get($event, 'data.store.id')
                ?? data_get($event, 'store.id')
                ?? 0
        );

        if ($storeId <= 0) {
            throw new \Exception('StoreDeletedHandler: missing/invalid store_id');
        }

        // Deliberately keep the row and change nothing, as UserDeletedHandler does.
        //
        // Deleting a store cascades to its stations, usage segments and daily
        // usage, which the reports keep showing after it closes. Access is
        // unaffected: pizzasys stops authorizing anyone at a deleted store, and
        // every manager request here is verified against it.
        Log::info('pizzasys store deleted; keeping the local row for usage history', [
            'store_id' => $storeId,
            'exists_locally' => Store::query()->whereKey($storeId)->exists(),
        ]);
    }
}
