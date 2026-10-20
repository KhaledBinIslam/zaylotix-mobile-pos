<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Season;
use Illuminate\Http\Request;

class SeasonController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        Season::create($data);

        return back()->with('success', 'সিজন যোগ করা হয়েছে।');
    }

    public function update(Request $request, Season $season)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $season->update($data);

        return back()->with('success', 'সিজন হালনাগাদ করা হয়েছে।');
    }

    /** Campaigns under this season are kept, not deleted (nullOnDelete on season_id) — a season is just an organizing label, removing it shouldn't take its campaigns' discount/message setup down with it. */
    public function destroy(Season $season)
    {
        $season->delete();

        return back()->with('success', 'সিজন মুছে ফেলা হয়েছে।');
    }
}
