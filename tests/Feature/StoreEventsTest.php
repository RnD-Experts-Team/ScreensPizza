<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\Store;
use App\Services\EventConsume\EventRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Store events as pizzasys's StoreManagementService records them, through the consumer's router. */
class StoreEventsTest extends TestCase
{
    use RefreshDatabase;

    private function handle(string $subject, array $data): void
    {
        app(app(EventRouter::class)->resolve($subject))->handle(['id' => 'evt', 'subject' => $subject, 'data' => $data]);
    }

    public function test_a_created_store_is_kept_with_its_number_and_drive_through_station(): void
    {
        $this->handle('auth.v1.store.created', ['store' => [
            'id' => 12, 'store_id' => '03795-00044', 'name' => 'Main St', 'metadata' => null, 'is_active' => true,
        ]]);

        $this->assertSame('03795-00044', Store::find(12)->store_number);
        $this->assertSame(['Drive Through'], Station::where('store_id', 12)->pluck('name')->all());

        // Replayed: same row, no second station.
        $this->handle('auth.v1.store.created', ['store' => ['id' => 12, 'store_id' => '03795-00044']]);
        $this->assertSame(1, Store::count());
        $this->assertSame(1, Station::where('store_id', 12)->count());
    }

    public function test_a_deleted_store_keeps_its_row_and_history(): void
    {
        Store::create(['id' => 12, 'store_number' => '03795-00044']);

        $this->handle('auth.v1.store.deleted', ['store_id' => 12, 'store_name' => 'Main St']);

        $this->assertNotNull(Store::find(12));
        $this->assertSame(1, Station::where('store_id', 12)->count());
    }

    public function test_store_updates_are_not_routed(): void
    {
        $this->expectExceptionMessage("No handler registered for subject 'auth.v1.store.updated'.");
        app(EventRouter::class)->resolve('auth.v1.store.updated');
    }
}
