<?php

namespace Modules\Channel\Helpers;

use Illuminate\Http\Request;

class TikTokSignature
{
    public static function generate(
        string $path,
        array $queries,
        $body,
        string $appSecret,
        string $contentType = 'application/json'
    ): string {
        $signParams = [];

        foreach ($queries as $k => $v) {
            if ($k !== 'sign' && $k !== 'access_token') {
                $signParams[$k] = $v;
            }
        }

        ksort($signParams);

        $paramString = '';

        foreach ($signParams as $k => $v) {
            $paramString .= $k . $v;
        }

        $signString = $path . $paramString;

        if (stripos($contentType, 'multipart/form-data') === false && !empty($body)) {
            if (is_array($body)) {
                $signString .= json_encode(
                    $body,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            } else {
                $signString .= (string) $body;
            }
        }

        $wrappedString = $appSecret . $signString . $appSecret;

        return hash_hmac('sha256', $wrappedString, $appSecret);
    }

    public static function generateFromRequest(
        Request $request,
        string $appSecret
    ): string {
        $path = $request->getPathInfo();
        $queries = $request->query();
        $body = $request->getContent();
        $contentType = $request->header('Content-Type', '');

        return self::generate(
            $path,
            $queries,
            $body,
            $appSecret,
            $contentType
        );
    }
}