<?php

namespace App\Http\Controllers;

use OpenApi\Annotations as OA;
use App\Traits\ApiResponse;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="Staging Superapp API Documentation",
 *      description="L5 Swagger OpenApi description",
 *      @OA\Contact(
 *          email="admin@example.com"
 *      )
 * )
 *
 * @OA\Server(
 *      url="http://localhost:8000",
 *      description="Demo API Server"
 * )
 *
 * @OA\SecurityScheme(
 *      securityScheme="bearerAuth",
 *      in="header",
 *      name="bearerAuth",
 *      type="http",
 *      scheme="bearer",
 *      bearerFormat="JWT",
 * )
 */
abstract class Controller
{
    use ApiResponse;
}
