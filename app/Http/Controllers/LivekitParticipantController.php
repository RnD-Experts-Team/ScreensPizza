<?php
namespace App\Http\Controllers;

use Agence104\LiveKit\RoomServiceClient;

class LivekitParticipantController extends Controller
{
    protected function client()
    {
        return new RoomServiceClient(
            config('livekit.host'),
            config('livekit.api_key'),
            config('livekit.api_secret')
        );
    }

    public function list($room)
    {
        $participants = $this->client()->listParticipants($room);
        $payload = json_decode($participants->serializeToJsonString(), true);

        return response()->json($payload ?? ['participants' => []]);
    }

    public function remove($room, $identity)
    {
        $this->client()->removeParticipant($room, $identity);
        return response()->noContent();
    }
}