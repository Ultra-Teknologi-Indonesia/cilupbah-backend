<?php

declare(strict_types=1);

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;

final class ChannelOperationLedger
{
    public static function claim(SalesOrder $order, string $operation): array
    {
        return DB::transaction(function () use ($order, $operation): array {
            // Lock the parent row first. A missing operation row cannot be
            // locked, so this serializes two first-time claims before either
            // process can insert the unique (order_id, operation) record.
            SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt = ChannelOperationAttempt::query()
                ->where('order_id', $order->id)
                ->where('operation', $operation)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                $attempt = ChannelOperationAttempt::create([
                    'order_id' => $order->id,
                    'operation' => $operation,
                    'status' => ChannelOperationAttempt::STATUS_SENDING,
                    'attempt_count' => 1,
                ]);

                return [
                    'attempt' => $attempt,
                    'should_execute' => true,
                    'needs_verification' => false,
                ];
            }

            if ($attempt->status === ChannelOperationAttempt::STATUS_SUCCEEDED) {
                return [
                    'attempt' => $attempt,
                    'should_execute' => false,
                    'needs_verification' => false,
                ];
            }

            if ($attempt->status === ChannelOperationAttempt::STATUS_RETRYABLE) {
                $attempt->forceFill([
                    'status' => ChannelOperationAttempt::STATUS_SENDING,
                    'attempt_count' => $attempt->attempt_count + 1,
                    'last_error' => null,
                ])->save();

                return [
                    'attempt' => $attempt,
                    'should_execute' => true,
                    'needs_verification' => false,
                ];
            }

            if ($attempt->status !== ChannelOperationAttempt::STATUS_ACCEPTED) {
                $attempt->forceFill([
                    'status' => ChannelOperationAttempt::STATUS_UNCERTAIN,
                    'uncertain_at' => now(),
                ])->save();
            }

            return [
                'attempt' => $attempt,
                'should_execute' => false,
                'needs_verification' => true,
            ];
        });
    }

    public static function markAccepted(ChannelOperationAttempt $attempt, ?array $response = null): void
    {
        $attempt->forceFill([
            'status' => ChannelOperationAttempt::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'last_response' => $response,
            'last_error' => null,
        ])->save();
    }

    public static function markSucceeded(ChannelOperationAttempt $attempt, ?array $response = null): void
    {
        $attempt->forceFill([
            'status' => ChannelOperationAttempt::STATUS_SUCCEEDED,
            'succeeded_at' => now(),
            'last_response' => $response,
            'last_error' => null,
        ])->save();
    }

    public static function markSucceededWhenVerified(
        SalesOrder $order,
        string $operation,
        ?array $response = null,
    ): void {
        DB::transaction(function () use ($order, $operation, $response): void {
            $attempt = ChannelOperationAttempt::query()
                ->where('order_id', $order->id)
                ->where('operation', $operation)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status === ChannelOperationAttempt::STATUS_SUCCEEDED) {
                return;
            }

            self::markSucceeded($attempt, $response);
        });
    }

    public static function beginVerification(
        string $orderId,
        string $operation,
        int $cooldownSeconds,
    ): bool {
        return DB::transaction(function () use ($orderId, $operation, $cooldownSeconds): bool {
            $attempt = ChannelOperationAttempt::query()
                ->where('order_id', $orderId)
                ->where('operation', $operation)
                ->lockForUpdate()
                ->first();

            if (
                $attempt === null
                || ! in_array($attempt->status, [
                    ChannelOperationAttempt::STATUS_ACCEPTED,
                    ChannelOperationAttempt::STATUS_UNCERTAIN,
                ], true)
                || $attempt->updated_at->greaterThan(now()->subSeconds($cooldownSeconds))
            ) {
                return false;
            }

            $attempt->touch();

            return true;
        });
    }

    public static function markUncertain(ChannelOperationAttempt $attempt, \Throwable $exception): void
    {
        $attempt->forceFill([
            'status' => ChannelOperationAttempt::STATUS_UNCERTAIN,
            'uncertain_at' => now(),
            'last_error' => Str::limit($exception->getMessage(), 1000),
        ])->save();
    }

    public static function markRetryable(
        ChannelOperationAttempt $attempt,
        string $reason,
        ?array $response = null,
    ): void {
        $attempt->forceFill([
            'status' => ChannelOperationAttempt::STATUS_RETRYABLE,
            'last_error' => Str::limit($reason, 1000),
            'last_response' => $response,
        ])->save();
    }

    public static function markRejected(ChannelOperationAttempt $attempt, string $reason): void
    {
        $attempt->forceFill([
            'status' => ChannelOperationAttempt::STATUS_REJECTED,
            'last_error' => Str::limit($reason, 1000),
        ])->save();
    }
}
