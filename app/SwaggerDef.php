<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "Staging Superapp API",
    description: "L5 Swagger OpenApi description"
)]
#[OA\Server(
    url: "http://localhost:8000",
    description: "Demo API Server (Docker)"
)]
class SwaggerDef
{
}
