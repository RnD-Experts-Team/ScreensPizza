<?php
namespace App\Http\Controllers;

use App\Models\Station;
use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use Illuminate\Http\Request;

class LivekitTokenController extends Controller
{
    // Station token to join own room
    public function station(Request $request)
    {
        $data = $request->validate([
            'station_id' => 'required|integer|exists:stations,id',
        ]);

        $station = Station::findOrFail($data['station_id']);
        $room = $station->room_name;
        $identity = 'station:' . $station->id;

        // Station: join + speak + listen only.
        $grant = (new VideoGrant())
            ->setRoomJoin()
            ->setRoomName($room)
            ->setCanPublish(true)
            ->setCanSubscribe(true)
            ->setCanPublishData(false);

        $token = (new AccessToken(
            config('livekit.api_key'),
            config('livekit.api_secret')
        ))
            ->init((new AccessTokenOptions())->setIdentity($identity))
            ->setGrant($grant)
            ->toJwt();

        return response()->json([
            'server_url' => config('livekit.host'),
            'room' => $room,
            'token' => $token,
        ]);
    }

    // Supervisor token for all rooms in a store
    public function supervisor(Request $request)
    {
        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
        ]);

        $rooms = Station::where('store_id', $data['store_id'])
            ->pluck('room_name')
            ->values();

        $identity = 'supervisor:' . $data['store_id'];

        // Room admin is room-scoped in LiveKit, so mint one admin token per room.
        $tokens = $rooms->map(function ($room) use ($identity) {
            $grant = (new VideoGrant())
                ->setRoomJoin(true)
                ->setRoomName($room)
                ->setRoomAdmin(true)
                ->setCanPublish(false)
                ->setCanSubscribe(true)
                ->setCanPublishData(false);

            $token = (new AccessToken(
                config('livekit.api_key'),
                config('livekit.api_secret')
            ))
                ->init((new AccessTokenOptions())->setIdentity($identity))
                ->setGrant($grant)
                ->toJwt();

            return [
                'room' => $room,
                'token' => $token,
            ];
        })->values();

        return response()->json([
            'server_url' => config('livekit.host'),
            'identity' => $identity,
            'rooms' => $rooms,
            'tokens' => $tokens,
            'permissions' => [
                'room_admin' => true,
                'room_join' => true,
                'can_subscribe' => true,
                'can_publish' => false,
                'can_publish_data' => false,
            ],
        ]);
    }
}