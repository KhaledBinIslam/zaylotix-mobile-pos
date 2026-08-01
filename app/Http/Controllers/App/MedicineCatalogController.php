<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\MedicineCatalog;
use Illuminate\Http\Request;

/** Search-only — a lookup helper for pre-filling the new-product form (name/generic/company), never a place products/prices actually live (see the catalog's own migration comment). */
class MedicineCatalogController extends Controller
{
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = MedicineCatalog::where('name', 'like', "%{$q}%")
            ->orWhere('generic_name', 'like', "%{$q}%")
            ->orWhere('company', 'like', "%{$q}%")
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'generic_name', 'company', 'form', 'strength']);

        return response()->json(['results' => $results]);
    }
}
