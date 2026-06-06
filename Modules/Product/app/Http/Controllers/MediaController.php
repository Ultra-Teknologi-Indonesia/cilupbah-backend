<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class MediaController extends Controller
{
    use ApiResponse;
    #[OA\Post(
        path: "/api/v1/media/upload",
        summary: "Upload media",
        description: "Endpoint khusus upload media yang kemudian diambil nama/id untuk dimasukan ke json.",
        tags: ["Media"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: "file", description: "File media (jpeg, png, jpg, gif, mp4, mov) max 20MB", type: "string", format: "binary")
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Media uploaded successfully",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "status", type: "string", example: "success"),
                new OA\Property(property: "message", type: "string", example: "Media uploaded successfully"),
                new OA\Property(property: "data", type: "object", properties: [
                    new OA\Property(property: "media_id", type: "string", example: "id-media_123.jpg"),
                    new OA\Property(property: "name", type: "string", example: "media_123.jpg"),
                    new OA\Property(property: "url", type: "string", example: "http://localhost/storage/media/media_123.jpg")
                ])
            ]
        )
    )]
    #[OA\Response(response: 400, description: "Bad Request")]
    public function upload(Request $request): JsonResponse
    {
        // Validasi input media
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,mp4,mov|max:20480', // Contoh max 20MB
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            
            // TODO: Implement actual upload logic to storage (e.g., S3, local, or directly to marketplace)
            // Contoh sederhana menyimpan secara lokal dan men-generate ID/URL
            
            $extension = $file->getClientOriginalExtension();
            $filename = uniqid('media_') . '.' . $extension;
            
            // Storage::disk('public')->putFileAs('media', $file, $filename);
            
            $mediaId = 'id-' . $filename; // Simulasi Media ID
            $mediaUrl = url('storage/media/' . $filename); // Simulasi URL

            return $this->successResponse([
                'media_id' => $mediaId,
                'name' => $filename,
                'url' => $mediaUrl,
            ], 'Media uploaded successfully', 201);
        }

        return $this->errorResponse('No file provided', 400);
    }
}
