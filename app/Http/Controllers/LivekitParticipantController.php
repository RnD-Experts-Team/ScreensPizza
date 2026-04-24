<?php
namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Store;
use Agence104\LiveKit\RoomServiceClient;
use Illuminate\Http\Request;

class LivekitParticipantController extends Controller
{
    protected function resolveStoreId(string $storeNumber): int
    {
        $store = Store::where('store_number', $storeNumber)->firstOrFail(['id']);
        return (int) $store->id;
    }

    protected function assertRoomBelongsToStore(int $storeId, string $roomName): void
    {
        Station::where('store_id', $storeId)
            ->where('room_name', $roomName)
            ->firstOrFail();
    }

    protected function client()
    {
        return new RoomServiceClient(
            config('livekit.host'),
            config('livekit.api_key'),
            config('livekit.api_secret')
        );
    }

    public function list(Request $request, $room)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $participants = $this->client()->listParticipants($room);
        $payload = json_decode($participants->serializeToJsonString(), true);

        return response()->json($payload ?? ['participants' => []]);
    }

    public function remove(Request $request, $room, $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $this->client()->removeParticipant($room, $identity);
        return response()->noContent();
    }
}