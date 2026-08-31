# Database availability and graceful degradation

## Scope

Protect API requests when PostgreSQL reaches its connection capacity and prevent cache, session, and queue traffic from consuming application database connections unnecessarily.

## Decisions

- Redis is the default backend for cache, session, and queue configuration.
- PostgreSQL remains the source of truth for transactional data.
- Transient database connectivity and capacity errors are returned as HTTP 503.
- The API response uses a stable error code, an Indonesian title and message, and a `Retry-After` header.
- Technical exception details are logged as server-side metadata and are not returned in production responses.
- The frontend does not automatically retry 503 responses, preventing a retry storm against the shared database.

## Acceptance criteria

- Database capacity failures never become a generic 500 response for API consumers.
- The frontend displays the API's safe Indonesian 503 message for normal and blob/download requests.
- Cache, session, and queue defaults do not require PostgreSQL.
- Existing 4xx responses and successful responses keep their current response shape.
- Tests cover capacity detection, non-capacity database errors, and the 503 response contract.
