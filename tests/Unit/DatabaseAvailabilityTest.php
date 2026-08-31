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

    public function test_invalid_payload_database_errors_are_permanent(): void
    {
        $uuidException = new \RuntimeException('SQLSTATE[22P02]: invalid input syntax for type uuid: "-"');
        $notNullException = new \RuntimeException('null value in column "total_tax" violates not-null constraint');

        self::assertTrue(DatabaseAvailability::isPermanentDataError($uuidException));
        self::assertTrue(DatabaseAvailability::isPermanentDataError($notNullException));
    }

    public function test_connection_capacity_error_is_not_classified_as_permanent_data_error(): void
    {
        $exception = new \RuntimeException('remaining connection slots are reserved for roles with the SUPERUSER attribute');

        self::assertFalse(DatabaseAvailability::isPermanentDataError($exception));
    }
}
