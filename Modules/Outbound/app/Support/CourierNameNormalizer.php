<?php

namespace Modules\Outbound\Support;

class CourierNameNormalizer
{
    public static function clean(?string $raw): string
    {
        $name = trim((string) $raw);
        if ($name === '') {
            return '';
        }

        if (preg_match('/delivery\s*:\s*(.+)$/i', $name, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/(?:drop-?off|pickup)\s*:\s*(.+)$/i', $name, $m)) {
            $name = trim($m[1]);
        }

        $name = preg_replace('/^\s*TT\s*Virtual#\s*/i', '', $name);
        $name = preg_replace('/^\s*Sandbox-\s*/i', '', $name);

        $name = preg_replace('/\s*\((?:don\'?t modify|test)\)\s*$/i', '', $name);

        return trim((string) $name, " \t,-");
    }
}
