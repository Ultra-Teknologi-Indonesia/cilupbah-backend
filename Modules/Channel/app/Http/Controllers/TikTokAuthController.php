<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokClient;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

class TikTokAuthController extends Controller
{
    use ApiResponse;

    public function redirect(TikTokClient $client)
    {
        $redirectUri = config('services.tiktok.redirect_uri');
        $url = $client->getAuthUrl($redirectUri);
        return $this->successResponse(['auth_url' => $url], 'Auth URL generated successfully.');
    }

    public function callback(Request $request, \Modules\Channel\Services\TikTokAuthService $authService)
    {
        // Temporary debug log to capture incoming TikTok callback payload for verification
        try {
            Log::info('TikTok callback received', [
                'query' => $request->query(),
                'ip' => $request->ip(),
                'headers' => $request->headers->all(),
                'body' => $request->all(),
            ]);
        } catch (\Throwable $e) {
            // ensure logging failure won't break callback flow
            Log::warning('Failed writing TikTok callback debug log', ['error' => $e->getMessage()]);
        }

        $code = $request->query('code');
        if (!$code) {
            return $this->errorResponse('Binding gagal: TikTok tidak mengirimkan kode otorisasi.', 400);
        }

        try {
            $redirectUri = config('services.tiktok.redirect_uri');
            $savedShops = $authService->handleCallback($code, $redirectUri);

            $shopNames = collect($savedShops)->pluck('shop_name')->join(', ');
            $count = count($savedShops);

            return $this->successResponse([
                'new_shops' => $savedShops
            ], "{$count} toko TikTok berhasil dihubungkan: {$shopNames}");
        } catch (\Exception $e) {
            // Log the exception details for debugging
            try {
                Log::error('TikTok callback processing failed', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $_) {
                // ignore logging failure
            }

            return $this->errorResponse('Binding gagal: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Temporary debug endpoint to read recent TikTok callback log entries.
     * Use only for debugging in staging and remove after verification.
     */
    public function callbackDebug(Request $request)
    {
        // Only allow in non-production environment as a safety guard
        if (config('app.env') === 'production') {
            return $this->errorResponse('Not available in production', 403);
        }

        $path = storage_path('logs/laravel.log');
        if (!file_exists($path)) {
            return $this->successResponse(['entries' => []], 'Log read success');
        }

        // Read last portion of the log to keep memory usage reasonable
        $lines = [];
        $handle = fopen($path, 'r');
        if ($handle) {
            $pos = -1;
            $chunk = '';
            $maxBytes = 1000000; // read up to ~1MB from the end
            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            $readFrom = max(0, $size - $maxBytes);
            fseek($handle, $readFrom);
            while (!feof($handle)) {
                $lines[] = fgets($handle);
            }
            fclose($handle);
        } else {
            // fallback: read whole file
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        }

        $entries = [];
        $needle = 'TikTok callback received';
        foreach ($lines as $line) {
            if (strpos($line, $needle) !== false) {
                // capture timestamp if present
                $timestamp = null;
                if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                    $timestamp = $m[1];
                }
                // try to extract JSON context after the message
                $pos = strpos($line, $needle);
                $jsonPart = trim(substr($line, $pos + strlen($needle)));
                $decoded = null;
                if ($jsonPart) {
                    // remove any leading colon or dash
                    $jsonPart = preg_replace('/^[^\{]*\{/', '{', $jsonPart);
                    $firstBrace = strpos($jsonPart, '{');
                    if ($firstBrace !== false) {
                        $jsonPart = substr($jsonPart, $firstBrace);
                        $decoded = json_decode($jsonPart, true);
                    }
                }
                $entries[] = [
                    'timestamp' => $timestamp,
                    'raw' => $line,
                    'data' => $decoded,
                ];
            }
        }

        // return newest first
        $entries = array_reverse($entries);

        return $this->successResponse(['entries' => $entries], 'Log read success');
    }
}
