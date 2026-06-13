<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AIController extends Controller
{

    public function index()
    {
        return view('ai::index');
    }

    public function create()
    {
        return view('ai::create');
    }

    public function store(Request $request) {}

    public function show($id)
    {
        return view('ai::show');
    }

    public function edit($id)
    {
        return view('ai::edit');
    }

    public function update(Request $request, $id) {}

    public function destroy($id) {}
}
