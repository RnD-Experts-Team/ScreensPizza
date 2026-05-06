<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function setStationPassword(Request $request, string $StoreId)
    {
        $data = $request->validate([
            'password' => 'required|string',
        ]);

        $store = Store::where('store_number', $StoreId)->firstOrFail();
        $store->update([
            'station_password' => $data['password'],
        ]);

        return response()->noContent();
    }
}
