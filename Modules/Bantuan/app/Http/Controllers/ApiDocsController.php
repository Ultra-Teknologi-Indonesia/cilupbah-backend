<?php

namespace Modules\Bantuan\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\Bantuan\Services\ApiDocsGenerator;
use Symfony\Component\HttpFoundation\Response;

class ApiDocsController extends Controller
{
    private const CACHE_DIR = 'bantuan';

    public function __construct(private ApiDocsGenerator $generator)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->readOrBuild('index.json', function () {
            return $this->generator->buildPerModule()['index.json'];
        }));
    }

    public function module(string $slug): JsonResponse
    {
        $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $slug));
        $file = "modules/{$slug}.json";

        $data = $this->readOrBuild($file, function () use ($file) {
            $files = $this->generator->buildPerModule();
            return $files[$file] ?? null;
        });

        if ($data === null) {
            return response()->json(['message' => "Modul '{$slug}' tidak ditemukan"], Response::HTTP_NOT_FOUND);
        }
        return response()->json($data);
    }

    private function readOrBuild(string $file, \Closure $builder): mixed
    {
        $path = storage_path('app/' . self::CACHE_DIR . '/' . $file);
        if (is_file($path)) {
            $raw = File::get($path);
            return json_decode($raw, true);
        }
        return $builder();
    }
}
