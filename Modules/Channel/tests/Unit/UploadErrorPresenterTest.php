<?php

namespace Modules\Channel\Tests\Unit;

use Illuminate\Queue\MaxAttemptsExceededException;
use Modules\Channel\Support\UploadErrorPresenter;
use PHPUnit\Framework\TestCase;

class UploadErrorPresenterTest extends TestCase
{
    public function test_queue_exhaustion_is_not_presented_as_channel_rejection(): void
    {
        $error = UploadErrorPresenter::fromThrowable(
            'lazada',
            new MaxAttemptsExceededException('SyncProductToChannelJob has been attempted too many times.'),
        );

        self::assertSame(UploadErrorPresenter::QUEUE, $error['category']);
        self::assertSame('Antrean upload bermasalah', $error['title']);
        self::assertTrue($error['retryable']);
        self::assertStringContainsString('melebihi batas percobaan', $error['reason']);
    }

    public function test_queue_message_is_classified_from_a_persisted_string(): void
    {
        $error = UploadErrorPresenter::fromMessage(
            'lazada',
            'Modules\\Channel\\Jobs\\SyncProductToChannelJob has been attempted too many times.',
        );

        self::assertSame(UploadErrorPresenter::QUEUE, $error['category']);
        self::assertSame('Antrean upload bermasalah', $error['title']);
    }
}
