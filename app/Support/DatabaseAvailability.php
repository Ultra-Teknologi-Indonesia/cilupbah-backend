<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class DatabaseAvailability
{
    private const CAPACITY_PATTERNS = [
        'remaining connection slots',
        'too many clients already',
        'sorry, too many clients',
    ];

    private const CONNECTION_PATTERNS = [
        'connection refused',
        'could not connect to server',
        'connection to server',
        'server closed the connection unexpectedly',
        'connection timed out',
        'connection timeout',
    ];

    private const PERMANENT_DATA_PATTERNS = [
        'invalid input syntax for type uuid',
        'invalid input syntax for integer',
        'invalid input syntax for numeric',
        'invalid text representation',
        'null value in column',
        'violates not-null constraint',
        'violates check constraint',
        'violates foreign key constraint',
    ];

    public static function isTransient(Throwable $exception): bool
    {
        $visited = [];
        $current = $exception;

        while ($current !== null && ! isset($visited[spl_object_id($current)])) {
            $visited[spl_object_id($current)] = true;
            $message = strtolower($current->getMessage());

            foreach (self::CAPACITY_PATTERNS as $pattern) {
                if (str_contains($message, $pattern)) {
                    return true;
                }
            }

            $hasConnectionMessage = false;
            foreach (self::CONNECTION_PATTERNS as $pattern) {
                if (str_contains($message, $pattern)) {
                    $hasConnectionMessage = true;
                    break;
                }
            }

            if ($hasConnectionMessage && self::isDatabaseException($current)) {
                return true;
            }

            $sqlState = strtoupper((string) $current->getCode());
            if (in_array($sqlState, ['08001', '08004', '08006', '53300'], true)) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    public static function isPermanentDataError(Throwable $exception): bool
    {
        $visited = [];
        $current = $exception;

        while ($current !== null && ! isset($visited[spl_object_id($current)])) {
            $visited[spl_object_id($current)] = true;
            $message = strtolower($current->getMessage());
            foreach (self::PERMANENT_DATA_PATTERNS as $pattern) {
                if (str_contains($message, $pattern)) {
                    return true;
                }
            }

            $sqlState = strtoupper((string) $current->getCode());
            if (in_array($sqlState, ['22P02', '23502', '23503', '23514'], true)) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    private static function isDatabaseException(Throwable $exception): bool
    {
        return $exception instanceof \PDOException
            || $exception instanceof QueryException
            || str_contains(strtolower($exception::class), 'database');
    }
}
