<?php

namespace Modules\Notification\Console;

use Illuminate\Console\Command;
use Modules\Notification\Services\FcmService;
use Modules\Notification\Models\DeviceToken;

class SendTestNotificationCommand extends Command
{
    protected $signature = 'notification:test {--user= : User ID to send to} {--token= : Direct FCM token}';
    protected $description = 'Send a test push notification';

    public function handle(FcmService $fcmService): int
    {
        $userId = $this->option('user');
        $token = $this->option('token');

        if (!$userId && !$token) {
            $tokens = DeviceToken::with('user')->latest()->take(10)->get();

            if ($tokens->isEmpty()) {
                $this->error('No device tokens registered. Login from the mobile app first.');
                return 1;
            }

            $this->table(
                ['#', 'User ID', 'Email', 'Platform', 'Token (prefix)'],
                $tokens->map(fn ($t, $i) => [
                    $i + 1,
                    $t->user_id,
                    $t->user?->email ?? '-',
                    $t->platform,
                    substr($t->fcm_token, 0, 30) . '...',
                ])
            );

            $choice = $this->ask('Send to which # (or type user ID)?');

            if (is_numeric($choice) && $choice <= $tokens->count()) {
                $selected = $tokens[$choice - 1];
                $token = $selected->fcm_token;
                $this->info("Sending to {$selected->user?->email} ({$selected->platform})");
            } else {
                $userId = $choice;
            }
        }

        $title = 'Test Notifikasi 🔔';
        $body = 'Ini adalah test push notification dari Cilupbah WMS.';

        if ($token) {
            $result = $fcmService->send($token, $title, $body, ['type' => 'test']);
            if ($result) {
                $this->info('✅ Notification sent successfully!');
                return 0;
            }
            $this->error('❌ Failed to send notification. Check logs.');
            return 1;
        }

        if ($userId) {
            $count = $fcmService->sendToUser($userId, $title, $body, ['type' => 'test']);
            if ($count > 0) {
                $this->info("✅ Sent to {$count} device(s) for user {$userId}");
                return 0;
            }
            $this->error("❌ No tokens found or failed to send for user {$userId}");
            return 1;
        }

        return 1;
    }
}
