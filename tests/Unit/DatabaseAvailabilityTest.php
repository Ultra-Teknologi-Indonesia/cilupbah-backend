<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DatabaseAvailability;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DatabaseAvailabilityTest extends TestCase
{
    public function test_connection_capacity_message_is_transient(): void
    {
        $exception = new PDOException('SQLSTATE[53300]: remaining connection slots are reserved');

        self::assertTrue(DatabaseAvailability::isTransient($exception));
    }

    public function test_wrapped_connection_failure_is_transient(): void
    {
        $previous = new PDOException('connection refused');
        $exception = new QueryException('pgsql', 'select 1', [], $previous);

        self::assertTrue(DatabaseAvailability::isTransient($exception));
    }

    public function test_unrelated_database_query_error_is_not_transient(): void
    {
        $exception = new QueryException(
            'pgsql',
            'select * from missing_table',
            [],
            new PDOException('relation does not exist'),
        );

        self::assertFalse(DatabaseAvailability::isTransient($exception));
    }
}
