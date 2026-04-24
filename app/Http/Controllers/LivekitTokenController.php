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

        // Build token with video grant
        $token = (new AccessToken(
            config('livekit.api_key'),
            config('livekit.api_secret')
        ))
            ->init((new AccessTokenOptions())->setIdentity($identity))
            ->setGrant(
                (new VideoGrant())->setRoomJoin()->setRoomName($room)
            )
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
            'store_id' => 'required|string',
        ]);

        // Fetch rooms for that store
        $stations = Station::where('store_id', $data['store_id'])->get();
        $tokens = [];

        foreach ($stations as $station) {
            $identity = 'supervisor:' . $data['store_id'];
            $room = $station->room_name;

            $tokens[] = [
                'room' => $room,
                'token' => (new AccessToken(
                    config('livekit.api_key'),
                    config('livekit.api_secret')
                ))
                    ->init((new AccessTokenOptions())->setIdentity($identity))
                    ->setGrant(
                        (new VideoGrant())->setRoomJoin()->setRoomName($room)
                    )
                    ->toJwt()
            ];
        }

        return response()->json([
            'server_url' => config('livekit.host'),
            'tokens' => $tokens,
        ]);
    }
}