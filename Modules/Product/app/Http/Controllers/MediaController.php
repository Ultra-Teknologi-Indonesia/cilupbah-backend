<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class MediaController extends Controller
{
    #[OA\Post(
        path: '/api/v1/media/upload',
        operationId: 'uploadMedia',
        summary: 'Upload a media file (image/video)',
        description: 'Uploads an image or video file and returns its public URL.',
        tags: ['Media']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [
                    new OA\Property(
                        property: 'file',
                        description: 'The media file to upload (image or video)',
                        type: 'string',
                        format: 'binary'
                    )
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'File uploaded successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'url', type: 'string', example: 'http://localhost:8000/storage/uploads/filename.jpg'),
                new OA\Property(property: 'filename', type: 'string', example: 'filename.jpg')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Validation failed or no file provided'
    )]
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,gif,mp4,mov,avi,webm|max:51200', // max 50MB
        ]);

        if ($request->hasFile('file')) {
            $tempUpload = \App\Models\TemporaryUpload::create();
            $media = $tempUpload->addMedia($request->file('file'))->toMediaCollection('default');

            return response()->json([
                'status' => 'success',
                'id' => $media->id,
                'url' => $media->getUrl(),
                'filename' => $media->file_name
            ], 201);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No file uploaded'
        ], 400);
    }
}
