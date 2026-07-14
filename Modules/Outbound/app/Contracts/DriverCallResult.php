<?php

namespace Modules\Outbound\Contracts;

class DriverCallResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NOT_SUPPORTED = 'not_supported';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param  array<int,array{order_id:string,status:string,message?:string,tracking_number?:string|null}>  $results
     */
    public function __construct(
        public readonly array $results = [],
    ) {}

    public function summary(): array
    {
        $success = 0;
        $failed = [];
        foreach ($this->results as $r) {
            if (($r['status'] ?? null) === self::STATUS_SUCCESS) {
                $success++;
            } else {
                $failed[] = [
                    'order_id' => $r['order_id'] ?? null,
                    'status' => $r['status'] ?? self::STATUS_FAILED,
                    'reason' => $r['message'] ?? null,
                ];
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
        ];
    }

    public function merge(DriverCallResult $other): DriverCallResult
    {
        return new self(array_merge($this->results, $other->results));
    }
}
