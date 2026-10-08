<?php

namespace App\Http\Controllers;

use App\Queries\DashboardSummary;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardSummary $summary)
    {
        $data = $summary->read($request->user());

        return response()->view('dashboard', $data, $data['mixedCurrencies'] || $data['corruptOwnerLink'] ? 409 : 200);
    }
}
