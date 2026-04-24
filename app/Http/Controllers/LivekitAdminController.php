<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Store;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Illuminate\Http\Request;
use Livekit\ParticipantPermission;

class LivekitAdminController extends Controller
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

    protected function roomClient(): RoomServiceClient
    {
        return new RoomServiceClient(
            config('livekit.host'),
            config('livekit.api_key'),
            config('livekit.api_secret')
        );
    }


    protected function toArray(mixed $message): array
    {
        if (is_object($message) && method_exists($message, 'serializeToJsonString')) {
            return json_decode($message->serializeToJsonString(), true) ?? [];
        }

        return json_decode(json_encode($message), true) ?? [];
    }

    public function listRooms(Request $request)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'names' => 'nullable|array',
            'names.*' => 'string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $storeRooms = Station::where('store_id', $storeId)->pluck('room_name')->all();
        $requestedNames = $data['names'] ?? [];
        $names = empty($requestedNames)
            ? $storeRooms
            : array_values(array_intersect($storeRooms, $requestedNames));

        $rooms = $this->roomClient()->listRooms($names);
        return response()->json($this->toArray($rooms));
    }

    public function createRoom(Request $request)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'name' => 'required|string',
            'empty_timeout' => 'nullable|integer|min:0',
            'max_participants' => 'nullable|integer|min:0',
            'node_id' => 'nullable|string',
            'metadata' => 'nullable|string',
            'min_playout_delay' => 'nullable|integer|min:0',
            'max_playout_delay' => 'nullable|integer|min:0',
            'sync_streams' => 'nullable|boolean',
            'room_preset' => 'nullable|string',
            'departure_timeout' => 'nullable|integer|min:0',
            'replay_enabled' => 'nullable|boolean',
        ]);

        $this->resolveStoreId($data['storeId']);

        $room = $this->roomClient()->createRoom(new RoomCreateOptions($data));
        return response()->json($this->toArray($room));
    }

    public function deleteRoom(Request $request, string $room)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);
        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $res = $this->roomClient()->deleteRoom($room);
        return response()->json($this->toArray($res));
    }

    public function updateRoomMetadata(Request $request, string $room)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'metadata' => 'required|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $updated = $this->roomClient()->updateRoomMetadata($room, $data['metadata']);
        return response()->json($this->toArray($updated));
    }

    public function listParticipants(Request $request, string $room)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);
        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $participants = $this->roomClient()->listParticipants($room);
        return response()->json($this->toArray($participants));
    }

    public function getParticipant(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);
        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $participant = $this->roomClient()->getParticipant($room, $identity);
        return response()->json($this->toArray($participant));
    }

    public function removeParticipant(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);
        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $res = $this->roomClient()->removeParticipant($room, $identity);
        return response()->json($this->toArray($res));
    }

    public function forwardParticipant(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'destination_room' => 'required|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);
        $this->assertRoomBelongsToStore($storeId, $data['destination_room']);

        $res = $this->roomClient()->forwardParticipant($room, $identity, $data['destination_room']);
        return response()->json($this->toArray($res));
    }

    public function moveParticipant(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'destination_room' => 'required|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);
        $this->assertRoomBelongsToStore($storeId, $data['destination_room']);

        $res = $this->roomClient()->moveParticipant($room, $identity, $data['destination_room']);
        return response()->json($this->toArray($res));
    }

    public function muteTrack(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'track_sid' => 'required|string',
            'muted' => 'required|boolean',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $res = $this->roomClient()->mutePublishedTrack($room, $identity, $data['track_sid'], $data['muted']);
        return response()->json($this->toArray($res));
    }

    public function updateParticipant(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'metadata' => 'nullable|string',
            'name' => 'nullable|string',
            'attributes' => 'nullable|array',
            'permission' => 'nullable|array',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $permission = null;
        if (isset($data['permission'])) {
            $permission = new ParticipantPermission($data['permission']);
        }

        $res = $this->roomClient()->updateParticipant(
            $room,
            $identity,
            $data['metadata'] ?? null,
            $permission,
            $data['name'] ?? null,
            $data['attributes'] ?? null
        );

        return response()->json($this->toArray($res));
    }

    public function updateSubscriptions(Request $request, string $room, string $identity)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'track_sids' => 'required|array',
            'track_sids.*' => 'string',
            'subscribe' => 'required|boolean',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $res = $this->roomClient()->updateSubscriptions($room, $identity, $data['track_sids'], $data['subscribe']);
        return response()->json($this->toArray($res));
    }

    public function sendData(Request $request, string $room)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'data' => 'required|string',
            'kind' => 'required|integer',
            'destination_identities' => 'nullable|array',
            'destination_identities.*' => 'string',
            'topic' => 'nullable|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        $this->assertRoomBelongsToStore($storeId, $room);

        $res = $this->roomClient()->sendData(
            $room,
            $data['data'],
            $data['kind'],
            $data['destination_identities'] ?? [],
            $data['topic'] ?? null
        );

        return response()->json($this->toArray($res));
    }

}
