<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TikTokSyncController extends Controller
{
    /**
     * Show the UI for TikTok Sync.
     */
    public function index()
    {
        return view('channel::tiktok-sync');
    }

    /**
     * Handle the push action.
     */
    public function push(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'shop_id' => 'required|string',
        ]);

        try {
            // We use Artisan::call to execute the console command
            Artisan::call('tiktok:push-product', [
                'product_id' => $request->product_id,
                'shop_id' => $request->shop_id,
            ]);

            $output = Artisan::output();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Push command executed successfully.',
                'output' => $output
            ]);
        } catch (\Exception $e) {
            Log::error('TikTok Push UI Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle the pull action.
     */
    public function pull(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|string',
        ]);

        try {
            Artisan::call('tiktok:pull-products', [
                'shop_id' => $request->shop_id,
            ]);

            $output = Artisan::output();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Pull command executed successfully.',
                'output' => $output
            ]);
        } catch (\Exception $e) {
            Log::error('TikTok Pull UI Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
