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

    public function index(Request $request)
    {
        $query = Station::query();

        if ($request->filled('storeId')) {
            $storeId = $this->resolveStoreId((string) $request->input('storeId'));
            $query->where('store_id', $storeId);
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
            'name' => 'required|string',
            'room_name' => 'required|string|unique:stations,room_name',
        ]);

        return Station::create([
            'store_id' => $this->resolveStoreId($data['storeId']),
            'name' => $data['name'],
            'room_name' => $data['room_name'],
        ]);
    }

    public function destroy(Request $request, Station $station)
    {
        $data = $request->validate([
            'storeId' => 'required|string',
        ]);

        $storeId = $this->resolveStoreId($data['storeId']);
        if ((int) $station->store_id !== $storeId) {
            abort(404, 'Station not found for provided storeId.');
        }

        $station->delete();
        return response()->noContent();
    }
}