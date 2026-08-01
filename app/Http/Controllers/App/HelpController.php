<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class HelpController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Help/Index');
    }
}
