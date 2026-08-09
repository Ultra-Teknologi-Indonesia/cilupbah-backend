<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Upload;
use App\Services\UploadService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[OA\Tag(name: 'Media', description: 'Manajemen file terpusat (R2)')]
class MediaController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected UploadService $uploadService,
        protected \Modules\Product\Services\MediaCleanupService $mediaCleanup,
    ) {}

    #[OA\Post(
        path: '/api/v1/media/upload',
        summary: 'Upload file (semua tipe)',
        description: 'Mengunggah file apa pun ke R2 dan mengembalikan uuid + url untuk direferensikan tabel lain.',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'File berhasil diunggah'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
        ]
    )]
    public function upload(StoreMediaRequest $request): JsonResponse
    {
        $media = $this->uploadService->store($request->file('file'), Auth::id());

        return $this->successResponse(new MediaResource($media), 'File berhasil diunggah.', 201);
    }

    #[OA\Get(
        path: '/api/v1/media/upload/{uuid}',
        summary: 'Ambil metadata + URL terkini berdasarkan UUID',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function show(string $uuid): JsonResponse
    {
        $media = $this->uploadService->findByUuid($uuid);

        if (! $media) {
            return $this->errorResponse('File tidak ditemukan.', 404);
        }

        return $this->successResponse(new MediaResource($media), 'Detail file berhasil diambil.');
    }

    #[OA\Put(
        path: '/api/v1/media/upload/{uuid}',
        summary: 'Ganti file (UUID tetap sama)',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'File berhasil diganti'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
        ]
    )]
    public function replace(StoreMediaRequest $request, string $uuid): JsonResponse
    {
        $existing = $this->uploadService->findByUuid($uuid);

        if (! $existing) {
            return $this->errorResponse('File tidak ditemukan.', 404);
        }

        $this->authorizeMediaMutation($existing);

        $media = $this->uploadService->replace($uuid, $request->file('file'));

        if (! $media) {
            return $this->errorResponse('File tidak ditemukan.', 404);
        }

        return $this->successResponse(new MediaResource($media), 'File berhasil diganti.');
    }

    #[OA\Delete(
        path: '/api/v1/media/upload/{uuid}',
        summary: 'Hapus file',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'File berhasil dihapus'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function destroy(string $uuid): JsonResponse
    {
        $media = $this->uploadService->findByUuid($uuid);

        if (! $media) {
            return $this->errorResponse('File tidak ditemukan.', 404);
        }

        $this->authorizeMediaMutation($media);

        $this->mediaCleanup->unlinkByMediaUuid($uuid);

        $this->uploadService->delete($uuid);

        return $this->successResponse(null, 'File berhasil dihapus.');
    }

    private function authorizeMediaMutation(Media $media): void
    {
        $user = Auth::user();

        $ownerModel = $media->model;
        $uploadedBy = $ownerModel instanceof Upload ? $ownerModel->uploaded_by : null;

        $owns = $uploadedBy !== null && (string) $uploadedBy === (string) $user->id;
        $privileged = $user->can('edit-produk') || $user->can('delete-produk');

        abort_unless($owns || $privileged, 403, 'Anda tidak berwenang mengubah berkas ini.');
    }
}
