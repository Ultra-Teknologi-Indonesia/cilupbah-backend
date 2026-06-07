<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reports', description: 'API Endpoints for Reports')]
#[OA\Schema(
    schema: 'Report',
    title: 'Report Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'title', type: 'string', example: 'Monthly Sales Report'),
        new OA\Property(property: 'type', type: 'string', example: 'SALES'),
        new OA\Property(property: 'content', type: 'string', example: 'Detailed sales data...'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreReportRequest',
    required: ['title', 'type', 'content'],
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', example: 'Monthly Sales Report'),
        new OA\Property(property: 'type', type: 'string', example: 'SALES'),
        new OA\Property(property: 'content', type: 'string', example: 'Detailed sales data...')
    ]
)]
class ReportController extends Controller
{
    #[OA\Get(
        path: '/api/v1/reports',
        summary: 'Get list of reports',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Report'))
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index()
    {
        return view('report::index');
    }

    public function create()
    {
        return view('report::create');
    }

    #[OA\Post(
        path: '/api/v1/reports',
        summary: 'Create a new report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreReportRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Report created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Report')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(Request $request) {}

    #[OA\Get(
        path: '/api/v1/reports/{report}',
        summary: 'Get report details',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, description: 'ID of the report', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Report')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Report not found')
        ]
    )]
    public function show($id)
    {
        return view('report::show');
    }

    public function edit($id)
    {
        return view('report::edit');
    }

    #[OA\Put(
        path: '/api/v1/reports/{report}',
        summary: 'Update an existing report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, description: 'ID of the report to update', schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreReportRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Report updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Report')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Report not found'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(Request $request, $id) {}

    #[OA\Delete(
        path: '/api/v1/reports/{report}',
        summary: 'Delete a report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, description: 'ID of the report to delete', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Report not found')
        ]
    )]
    public function destroy($id) {}
}
