<?php
namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Store;
use Illuminate\Http\Request;

class StationController extends Controller
{
    protected function resolveStoreId(string $storeNumber): int
    {
        $store = Store::where('store_number', $storeNumber)->firstOrFail(['id']);
        return (int) $store->id;
    }

    public function index(string $StoreId)
    {
        $storeId = $this->resolveStoreId($StoreId);

        return Station::where('store_id', $storeId)->get();
    }

    public function store(Request $request, string $StoreId)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'room_name' => 'required|string|unique:stations,room_name',
        ]);

        return Station::create([
            'store_id' => $this->resolveStoreId($StoreId),
            'name' => $data['name'],
            'room_name' => $data['room_name'],
        ]);
    }

    public function destroy(string $StoreId, Station $station)
    {
        $storeId = $this->resolveStoreId($StoreId);
        if ((int) $station->store_id !== $storeId) {
            abort(404, 'Station not found for provided storeId.');
        }

        $station->delete();
        return response()->noContent();
    }
}