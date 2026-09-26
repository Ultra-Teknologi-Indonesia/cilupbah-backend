<?php

declare(strict_types=1);

namespace App\Database;

use DateTimeInterface;
use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use PDO;

final class PostgresConnection extends BasePostgresConnection
{
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            }
        }

        return $bindings;
    }

    public function bindValues($statement, $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR,
                },
            );
        }
    }
}
