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
        return $this->client()->listParticipants($room);
    }

    public function remove($room, $identity)
    {
        $this->client()->removeParticipant($room, $identity);
        return response()->noContent();
    }
}