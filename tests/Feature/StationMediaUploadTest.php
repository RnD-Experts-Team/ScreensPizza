<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\StationMedia;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StationMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/verify',
            'services.auth_server.service_name' => 'Screens',
            'services.auth_server.call_token' => 'call-token',
        ]);

        Http::fake([
            'auth.test/*' => Http::response([
                'active' => true,
                'subject_type' => 'user',
                'user' => ['id' => 42, 'name' => 'Maria Manager'],
                'ext' => ['authorized' => true],
            ]),
        ]);

        Storage::fake('local');
        Storage::fake('public');

        Store::create(['id' => 7, 'store_number' => '1001']);
        $this->station = Station::create([
            'store_id' => 7,
            'name' => 'Make line',
            'room_name' => 'make-line-1001',
            'type' => Station::TYPE_STANDARD,
        ]);
    }

    private function base(): string
    {
        return "/api/1001/stations/{$this->station->id}/media/uploads";
    }

    private function sendChunk(string $uploadId, int $index, string $bytes)
    {
        // A real UploadedFile, not UploadedFile::fake(): the fake holds a
        // public stream the auth middleware cannot JSON-encode into its
        // store_context, which a real browser upload never does.
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $bytes);

        return $this->withToken('user-token')->post(
            $this->base()."/{$uploadId}/chunks/{$index}",
            ['chunk' => new UploadedFile($path, 'blob', 'application/octet-stream', null, true)],
            ['Accept' => 'application/json'],
        );
    }

    public function test_chunks_sent_out_of_order_and_retried_assemble_into_the_file(): void
    {
        $parts = ['aaaa', 'bbbb', 'cc'];
        $uploadId = $this->withToken('user-token')
            ->postJson($this->base(), ['total_chunks' => 3])
            ->assertOk()
            ->json('upload_id');

        $this->sendChunk($uploadId, 2, $parts[2])->assertOk();
        $this->sendChunk($uploadId, 0, 'garbage from a dropped attempt')->assertOk();
        $this->sendChunk($uploadId, 0, $parts[0])->assertOk();
        $this->sendChunk($uploadId, 1, $parts[1])->assertOk();

        $this->withToken('user-token')->postJson($this->base().'/complete-bulk', [
            'uploads' => [[
                'upload_id' => $uploadId,
                'type' => 'video',
                'file_name' => 'promo.mp4',
                'mime_type' => 'video/mp4',
                'total_size' => 10,
                'total_chunks' => 3,
            ]],
        ])->assertOk()->assertJsonPath('results.0.upload_id', $uploadId)->assertJsonMissingPath('results.0.error');

        $media = StationMedia::sole();
        Storage::disk('public')->assertExists($media->path);
        $this->assertSame('aaaabbbbcc', Storage::disk('public')->get($media->path));
    }

    public function test_a_missing_chunk_is_reported_per_upload(): void
    {
        $uploadId = $this->withToken('user-token')->postJson($this->base(), ['total_chunks' => 2])->json('upload_id');
        $this->sendChunk($uploadId, 0, 'aaaa')->assertOk();

        $this->withToken('user-token')->postJson($this->base().'/complete-bulk', [
            'uploads' => [[
                'upload_id' => $uploadId,
                'type' => 'video',
                'file_name' => 'promo.mp4',
                'total_size' => 8,
                'total_chunks' => 2,
            ]],
        ])->assertOk()->assertJsonPath('results.0.error', 'Missing chunks.');

        $this->assertSame(0, StationMedia::count());
    }

    public function test_upload_ids_that_are_not_uuids_are_refused(): void
    {
        $this->sendChunk('..', 0, 'aaaa')->assertNotFound();
        $this->sendChunk('not-a-uuid', 0, 'aaaa')->assertNotFound();

        $this->withToken('user-token')->postJson($this->base().'/complete-bulk', [
            'uploads' => [[
                'upload_id' => '../../x',
                'type' => 'video',
                'file_name' => 'promo.mp4',
                'total_size' => 4,
                'total_chunks' => 1,
            ]],
        ])->assertUnprocessable();
    }
}
