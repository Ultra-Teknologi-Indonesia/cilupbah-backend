<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth', description: 'API Endpoints for Authentication')]
#[OA\Schema(
    schema: 'Auth',
    title: 'Auth Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'token', type: 'string', example: '1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
        new OA\Property(property: 'device_name', type: 'string', example: 'Android Default Device'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreAuthRequest',
    required: ['email', 'password'],
    type: 'object',
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@example.com'),
        new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret'),
        new OA\Property(property: 'device_name', type: 'string', example: 'Web Browser', nullable: true)
    ]
)]
class AuthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auths',
        summary: 'Get list of active sessions',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Auth'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index()
    {
        return view('auth::index');
    }

    public function create()
    {
        return view('auth::create');
    }

    #[OA\Post(
        path: '/api/v1/auths',
        summary: 'Create a new authentication session (Login)',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreAuthRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Authentication successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Auth')
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(Request $request) {}

    #[OA\Get(
        path: '/api/v1/auths/{auth}',
        summary: 'Get authentication session details',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        parameters: [
            new OA\Parameter(name: 'auth', in: 'path', required: true, description: 'ID of the auth session', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Auth')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Session not found')
        ]
    )]
    public function show($id)
    {
        return view('auth::show');
    }

    public function edit($id)
    {
        return view('auth::edit');
    }

    #[OA\Put(
        path: '/api/v1/auths/{auth}',
        summary: 'Update an existing authentication session',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        parameters: [
            new OA\Parameter(name: 'auth', in: 'path', required: true, description: 'ID of the auth session to update', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreAuthRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Auth')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Session not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id) {}

    #[OA\Delete(
        path: '/api/v1/auths/{auth}',
        summary: 'Delete an authentication session (Logout)',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        parameters: [
            new OA\Parameter(name: 'auth', in: 'path', required: true, description: 'ID of the auth session to delete', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Logout successful'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Session not found')
        ]
    )]
    public function destroy($id) {}
}
