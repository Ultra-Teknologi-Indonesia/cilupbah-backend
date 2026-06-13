<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FinanceController extends Controller
{

    public function index()
    {
        return view('finance::index');
    }

    public function create()
    {
        return view('finance::create');
    }

    public function store(Request $request) {}

    public function show($id)
    {
        return view('finance::show');
    }

    public function edit($id)
    {
        return view('finance::edit');
    }

    public function update(Request $request, $id) {}

    public function destroy($id) {}
}
