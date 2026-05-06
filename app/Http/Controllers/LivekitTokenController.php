<?php
namespace App\Http\Controllers;

use App\Models\LivekitIssuedToken;
use App\Models\Store;
use App\Models\Station;
use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LivekitTokenController extends Controller
{
    protected function resolveStoreId(string $storeNumber): int
    {
        $store = Store::where('store_number', $storeNumber)->firstOrFail(['id']);
        return (int) $store->id;
    }

    protected function storeIssuedToken(
        string $scope,
        string $identity,
        ?string $room,
        string $jwt,
        int $ttlSeconds,
        array $metadata = []
    ): void {
        LivekitIssuedToken::create([
            'scope' => $scope,
            'identity' => $identity,
            'room' => $room,
            'token_hash' => hash('sha256', $jwt),
            'issued_at' => now(),
            'expires_at' => Carbon::now()->addSeconds($ttlSeconds),
            'metadata' => $metadata,
        ]);
    }

    public function index(Request $request)
    {
        $storeId = null;
        if ($request->filled('storeId')) {
            $storeId = $this->resolveStoreId((string) $request->input('storeId'));
        }

        $query = LivekitIssuedToken::query()->latest('id');

        if ($request->filled('identity')) {
            $query->where('identity', $request->string('identity'));
        }

        if ($request->filled('room')) {
            $query->where('room', $request->string('room'));
        }

        if ($request->filled('scope')) {
            $query->where('scope', $request->string('scope'));
        }

        if ($request->boolean('revoked')) {
            $query->whereNotNull('revoked_at');
        }

        if ($request->boolean('active_only')) {
            $query->whereNull('revoked_at')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
        }

        if ($storeId !== null) {
            $query->where('metadata->store_id', $storeId);
        }

        return response()->json([
            'tokens' => $query->paginate((int) $request->input('per_page', 50)),
            'note' => 'Revoking here only marks token as blocked in your app records; LiveKit cannot invalidate already-issued JWTs centrally.',
        ]);
    }

    public function revoke(int $id)
    {
        $token = LivekitIssuedToken::findOrFail($id);
        $token->update([
            'revoked_at' => now(),
        ]);

        return response()->json([
            'id' => $token->id,
            'revoked_at' => $token->revoked_at,
            'note' => 'Token marked revoked in registry. Existing JWT remains valid to LiveKit until expiration.',
        ]);
    }

    public function parse(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
        ]);

        $claims = (new AccessToken(
            config('livekit.api_key'),
            config('livekit.api_secret')
        ))->fromJwt($data['token']);

        return response()->json([
            'claims' => $claims->getData(),
        ]);
    }

    public function custom(Request $request)
    {
        $data = $request->validate([
            'storeId' => 'nullable|string',
            'identity' => 'required|string',
            'ttl' => 'nullable|integer|min:1',
            'name' => 'nullable|string',
            'metadata' => 'nullable|string',
            'attributes' => 'nullable|array',
            'video' => 'required|array',
            'video.room' => 'nullable|string',
            'video.room_join' => 'nullable|boolean',
            'video.room_admin' => 'nullable|boolean',
            'video.room_create' => 'nullable|boolean',
            'video.room_list' => 'nullable|boolean',
            'video.room_record' => 'nullable|boolean',
            'video.ingress_admin' => 'nullable|boolean',
            'video.can_publish' => 'nullable|boolean',
            'video.can_subscribe' => 'nullable|boolean',
            'video.can_publish_data' => 'nullable|boolean',
            'video.can_update_own_metadata' => 'nullable|boolean',
            'video.can_subscribe_metrics' => 'nullable|boolean',
            'video.hidden' => 'nullable|boolean',
            'video.recorder' => 'nullable|boolean',
        ]);

        $opts = (new AccessTokenOptions())
            ->setIdentity($data['identity']);

        if (isset($data['ttl'])) {
            $opts->setTtl($data['ttl']);
        }
        if (isset($data['name'])) {
            $opts->setName($data['name']);
        }
        if (isset($data['metadata'])) {
            $opts->setMetadata($data['metadata']);
        }
        if (isset($data['attributes'])) {
            $opts->setAttributes($data['attributes']);
        }

        $video = $data['video'];
        $storeId = isset($data['storeId']) ? $this->resolveStoreId($data['storeId']) : null;

        if ($storeId !== null && !empty($video['room'])) {
            Station::where('store_id', $storeId)
                ->where('room_name', $video['room'])
                ->firstOrFail();
        }

        $grant = new VideoGrant();

        if (isset($video['room'])) {
            $grant->setRoomName($video['room']);
        }
        if (isset($video['room_join'])) {
            $grant->setRoomJoin($video['room_join']);
        }
        if (isset($video['room_admin'])) {
            $grant->setRoomAdmin($video['room_admin']);
        }
        if (isset($video['room_create'])) {
            $grant->setRoomCreate($video['room_create']);
        }
        if (isset($video['room_list'])) {
            $grant->setRoomList($video['room_list']);
        }
        if (isset($video['room_record'])) {
            $grant->setRoomRecord($video['room_record']);
        }
        if (isset($video['ingress_admin'])) {
            $grant->setIngressAdmin($video['ingress_admin']);
        }
        if (isset($video['can_publish'])) {
            $grant->setCanPublish($video['can_publish']);
        }
        if (isset($video['can_subscribe'])) {
            $grant->setCanSubscribe($video['can_subscribe']);
        }
        if (isset($video['can_publish_data'])) {
            $grant->setCanPublishData($video['can_publish_data']);
        }
        if (isset($video['can_update_own_metadata'])) {
            $grant->setCanUpdateOwnMetadata($video['can_update_own_metadata']);
        }
        if (isset($video['can_subscribe_metrics'])) {
            $grant->setCanSubscribeMetrics($video['can_subscribe_metrics']);
        }
        if (isset($video['hidden'])) {
            $grant->setHidden($video['hidden']);
        }
        if (isset($video['recorder'])) {
            $grant->setRecorder($video['recorder']);
        }

        $ttl = (int) ($data['ttl'] ?? (4 * 60 * 60));
        $token = (new AccessToken(
            config('livekit.api_key'),
            config('livekit.api_secret')
        ))
            ->init($opts)
            ->setGrant($grant)
            ->toJwt();

        $this->storeIssuedToken(
            'custom',
            $data['identity'],
            $video['room'] ?? null,
            $token,
            $ttl,
            [
                'store_id' => $storeId,
                'store_number' => $data['storeId'] ?? null,
                'video' => $video,
            ]
        );

        return response()->json([
            'server_url' => config('livekit.host'),
            'storeId' => $data['storeId'] ?? null,
            'store_id' => $storeId,
            'identity' => $data['identity'],
            'token' => $token,
            'video' => $video,
        ]);
    }

    // Station token to join own room
    public function station(Request $request, string $StoreId, Station $station)
    {
        $data = $request->validate([
            'password' => 'required|string',
        ]);

        $store = Store::where('store_number', $StoreId)->firstOrFail();
        if (empty($store->station_password) || !Hash::check($data['password'], $store->station_password)) {
            abort(403, 'Invalid station password.');
        }

        $storeId = (int) $store->id;
        if ((int) $station->store_id !== $storeId) {
            abort(404, 'Station not found for provided storeId.');
        }

        $room = $station->room_name;
        $identity = 'station:' . $station->id;
        $ttl = 4 * 60 * 60;

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
            ->init(
                (new AccessTokenOptions())
                    ->setIdentity($identity)
                    ->setTtl($ttl)
            )
            ->setGrant($grant)
            ->toJwt();

        $this->storeIssuedToken('station', $identity, $room, $token, $ttl, [
            'store_id' => $storeId,
            'store_number' => $StoreId,
            'can_publish' => true,
            'can_subscribe' => true,
            'can_publish_data' => false,
        ]);

        return response()->json([
            'server_url' => config('livekit.host'),
            'storeId' => $StoreId,
            'store_id' => $storeId,
            'room' => $room,
            'token' => $token,
        ]);
    }

    // Supervisor token for all rooms in a store
    public function supervisor(string $StoreId)
    {
        $storeId = $this->resolveStoreId($StoreId);

        $rooms = Station::where('store_id', $storeId)
            ->pluck('room_name')
            ->values();

        $identity = 'supervisor:' . $storeId;
        $storeNumber = $StoreId;
        $ttl = 4 * 60 * 60;

        // Room admin is room-scoped in LiveKit, so mint one admin token per room.
        $tokens = $rooms->map(function ($room) use ($identity, $ttl, $storeId, $storeNumber) {
            $grant = (new VideoGrant())
                ->setRoomJoin(true)
                ->setRoomName($room)
                ->setRoomAdmin(true)
                ->setRoomList(true)
                ->setRoomRecord(true)
                ->setIngressAdmin(true)
                ->setCanPublish(true)
                ->setCanSubscribe(true)
                ->setCanPublishData(true)
                ->setCanUpdateOwnMetadata(true)
                ->setCanSubscribeMetrics(true);

            $token = (new AccessToken(
                config('livekit.api_key'),
                config('livekit.api_secret')
            ))
                ->init(
                    (new AccessTokenOptions())
                        ->setIdentity($identity)
                        ->setTtl($ttl)
                )
                ->setGrant($grant)
                ->toJwt();

            $this->storeIssuedToken('supervisor', $identity, $room, $token, $ttl, [
                'store_id' => $storeId,
                'store_number' => $storeNumber,
                'room_admin' => true,
                'room_join' => true,
                'room_list' => true,
                'room_record' => true,
                'ingress_admin' => true,
                'can_subscribe' => true,
                'can_publish' => true,
                'can_publish_data' => true,
                'can_update_own_metadata' => true,
                'can_subscribe_metrics' => true,
            ]);

            return [
                'room' => $room,
                'token' => $token,
            ];
        })->values();

        return response()->json([
            'server_url' => config('livekit.host'),
            'storeId' => $StoreId,
            'store_id' => $storeId,
            'identity' => $identity,
            'rooms' => $rooms,
            'tokens' => $tokens,
            'permissions' => [
                'room_admin' => true,
                'room_join' => true,
                'room_list' => true,
                'room_record' => true,
                'ingress_admin' => true,
                'can_subscribe' => true,
                'can_publish' => true,
                'can_publish_data' => true,
                'can_update_own_metadata' => true,
                'can_subscribe_metrics' => true,
            ],
        ]);
    }
}