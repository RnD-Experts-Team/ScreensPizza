<?php
namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function index()
    {
        return Station::all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => 'required|string',
            'name' => 'required|string',
            'room_name' => 'required|string|unique:stations,room_name',
        ]);

        return Station::create($data);
    }

    public function destroy(Station $station)
    {
        $station->delete();
        return response()->noContent();
    }
}