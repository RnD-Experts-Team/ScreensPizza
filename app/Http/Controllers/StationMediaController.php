<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\StationMedia;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StationMediaController extends Controller
{
    protected function resolveStore(string $storeNumber): Store
    {
        return Store::where('store_number', $storeNumber)->firstOrFail();
    }

    protected function assertStationInStore(Store $store, Station $station): void
    {
        if ((int) $station->store_id !== (int) $store->id) {
            abort(404, 'Station not found for provided storeId.');
        }
    }

    protected function mediaCollection(Station $station)
    {
        return $station->media()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(function (StationMedia $media) {
                return $media->toArray();
            })
            ->values();
    }

    protected function uploadTempPath(string $storeNumber, Station $station, string $uploadId): string
    {
        return 'station-media/uploads/' . $storeNumber . '/' . $station->id . '/' . $uploadId;
    }

    protected function finalizeUpload(Station $station, string $storeNumber, string $uploadId, array $data): StationMedia
    {
        $tempPath = $this->uploadTempPath($storeNumber, $station, $uploadId);

        $missingChunks = [];
        for ($i = 0; $i < (int) $data['total_chunks']; $i++) {
            $chunkPath = $tempPath . '/' . $i . '.part';
            if (!Storage::disk('local')->exists($chunkPath)) {
                $missingChunks[] = $i;
            }
        }

        if (count($missingChunks) > 0) {
            abort(422, 'Missing chunks.');
        }

        $extension = pathinfo($data['file_name'], PATHINFO_EXTENSION);
        $extension = $extension !== '' ? $extension : ($data['type'] === 'video' ? 'mp4' : 'jpg');
        $finalPath = 'station-media/' . $storeNumber . '/' . $station->id . '/' . $uploadId . '.' . $extension;
        $finalDisk = 'public';

        $finalFullPath = Storage::disk($finalDisk)->path($finalPath);
        $finalDir = dirname($finalFullPath);
        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0755, true);
        }

        $output = fopen($finalFullPath, 'wb');
        if ($output === false) {
            abort(500, 'Unable to create output file.');
        }

        try {
            for ($i = 0; $i < (int) $data['total_chunks']; $i++) {
                $chunkPath = Storage::disk('local')->path($tempPath . '/' . $i . '.part');
                $input = fopen($chunkPath, 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Unable to read chunk: ' . $i);
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } finally {
            fclose($output);
        }

        $finalSize = filesize($finalFullPath) ?: null;
        if ($finalSize === null || (int) $finalSize !== (int) $data['total_size']) {
            @unlink($finalFullPath);
            abort(422, 'Final file size does not match expected size.');
        }

        $media = StationMedia::create([
            'station_id' => $station->id,
            'type' => $data['type'],
            'is_primary' => false,
            'storage_disk' => $finalDisk,
            'path' => $finalPath,
            'file_name' => $data['file_name'],
            'mime_type' => $data['mime_type'] ?? null,
            'size_bytes' => $finalSize,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
        ]);

        if (($data['is_primary'] ?? false) === true) {
            StationMedia::where('station_id', $station->id)->update(['is_primary' => false]);
            $media->update(['is_primary' => true]);
        }

        Storage::disk('local')->deleteDirectory($tempPath);

        return $media;
    }

    public function index(string $StoreId, Station $station)
    {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        return response()->json([
            'station_id' => $station->id,
            'media' => $this->mediaCollection($station),
        ]);
    }

    public function initUpload(Request $request, string $StoreId, Station $station)
    {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        $data = $request->validate([
            'total_chunks' => 'required|integer|min:1',
        ]);

        $uploadId = (string) Str::uuid();

        return response()->json([
            'upload_id' => $uploadId,
            'station_id' => $station->id,
            'total_chunks' => $data['total_chunks'],
        ]);
    }

    public function uploadChunk(
        Request $request,
        string $StoreId,
        Station $station,
        string $uploadId,
        int $chunkIndex
    ) {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        if ($chunkIndex < 0) {
            abort(422, 'Invalid chunk index.');
        }

        $data = $request->validate([
            'chunk' => 'required|file',
        ]);

        $chunkName = $chunkIndex . '.part';
        $tempPath = $this->uploadTempPath($StoreId, $station, $uploadId);
        $chunkPath = $tempPath . '/' . $chunkName;

        Storage::disk('local')->putFileAs($tempPath, $data['chunk'], $chunkName);

        return response()->json([
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
            'path' => $chunkPath,
        ]);
    }

    public function completeUpload(Request $request, string $StoreId, Station $station, string $uploadId)
    {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        $data = $request->validate([
            'type' => 'required|string|in:image,video',
            'file_name' => 'required|string',
            'mime_type' => 'nullable|string',
            'total_size' => 'required|integer|min:1',
            'total_chunks' => 'required|integer|min:1',
            'duration_seconds' => 'nullable|integer|min:0',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
            'is_primary' => 'nullable|boolean',
        ]);

        $media = $this->finalizeUpload($station, $StoreId, $uploadId, $data);

        return response()->json([
            'media' => $media->toArray(),
        ]);
    }

    public function completeUploadsBulk(Request $request, string $StoreId, Station $station)
    {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        $data = $request->validate([
            'uploads' => 'required|array|min:1',
            'uploads.*.upload_id' => 'required|string',
            'uploads.*.type' => 'required|string|in:image,video',
            'uploads.*.file_name' => 'required|string',
            'uploads.*.mime_type' => 'nullable|string',
            'uploads.*.total_size' => 'required|integer|min:1',
            'uploads.*.total_chunks' => 'required|integer|min:1',
            'uploads.*.duration_seconds' => 'nullable|integer|min:0',
            'uploads.*.width' => 'nullable|integer|min:1',
            'uploads.*.height' => 'nullable|integer|min:1',
            'uploads.*.is_primary' => 'nullable|boolean',
        ]);

        $results = [];
        foreach ($data['uploads'] as $payload) {
            $uploadId = $payload['upload_id'];
            try {
                $results[] = [
                    'upload_id' => $uploadId,
                    'media' => $this->finalizeUpload($station, $StoreId, $uploadId, $payload)->toArray(),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'upload_id' => $uploadId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'results' => $results,
        ]);
    }

    public function setPrimary(string $StoreId, Station $station, StationMedia $media)
    {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        if ((int) $media->station_id !== (int) $station->id) {
            abort(404, 'Media not found for provided station.');
        }

        StationMedia::where('station_id', $station->id)->update(['is_primary' => false]);
        $media->update(['is_primary' => true]);

        return response()->json([
            'media' => $media->fresh()->toArray(),
        ]);
    }

    public function bulkDelete(Request $request, string $StoreId, Station $station)
    {
        $store = $this->resolveStore($StoreId);
        $this->assertStationInStore($store, $station);

        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $mediaItems = StationMedia::where('station_id', $station->id)
            ->whereIn('id', $data['ids'])
            ->get();

        foreach ($mediaItems as $media) {
            Storage::disk($media->storage_disk)->delete($media->path);
            $media->delete();
        }

        return response()->json([
            'deleted_ids' => $mediaItems->pluck('id')->values(),
        ]);
    }
}
