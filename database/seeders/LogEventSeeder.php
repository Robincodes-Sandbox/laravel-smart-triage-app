<?php

namespace Database\Seeders;

use App\Models\LogEvent;
use Illuminate\Database\Seeder;

class LogEventSeeder extends Seeder
{
    /**
     * A believable half-hour of a mid-sized app's logs. Deliberately includes
     * loud INFO lines and quiet WARN lines, because that mismatch is the thing
     * worth demonstrating: severity is a column, importance is a judgement.
     */
    protected array $events = [
        ['ERROR', 'checkout', 'Stripe webhook signature verification failed for evt_1P9x — payload rejected', 6],
        ['WARN', 'checkout', 'Payment gateway p99 latency 4200ms, threshold 800ms, 14 requests over', 14],
        ['ERROR', 'checkout', 'Order 88213 left in pending_payment for 900s with no callback', 1],
        ['INFO', 'checkout', 'Applied discount code BLACKFRIDAY to 412 baskets', 412],
        ['ERROR', 'auth', 'Failed login for user 8812 from 3 distinct countries in 90s', 3],
        ['WARN', 'auth', 'Password reset requested 40 times for the same address', 40],
        ['INFO', 'auth', 'Session store pruned 12,904 expired sessions', 1],
        ['ERROR', 'images', 'Thumbnail generation failed for asset 99213: unsupported colour profile', 1],
        ['ERROR', 'images', 'Thumbnail worker OOM-killed, 240 jobs returned to the queue', 1],
        ['WARN', 'queue', 'Default queue depth 4,812 and rising, oldest job waiting 340s', 1],
        ['INFO', 'queue', 'Horizon supervisor scaled checkout workers 4 -> 12', 1],
        ['DEBUG', 'cache', 'Redis connection pool resized 8 -> 12', 1],
        ['WARN', 'cache', 'Cache hit rate fell to 31% on the product catalogue', 1],
        ['ERROR', 'search', 'Scout indexing failed for 3 products: connection reset by peer', 3],
        ['INFO', 'cron', 'Nightly digest mailer finished in 42s, 8,140 sent', 1],
        ['ERROR', 'cron', 'Invoice reconciliation job exited non-zero after 4 retries', 4],
        ['WARN', 'storage', 'Disk usage on /var/uploads at 91%', 1],
        ['DEBUG', 'http', 'Rate limiter released 200 held requests', 1],
        ['ERROR', 'webhooks', 'Outbound webhook to partner "logistica" failed 50x, circuit opened', 50],
        ['INFO', 'deploy', 'Release 2026.09.19-3 completed, 12 containers healthy', 1],
    ];

    public function run(): void
    {
        foreach ($this->events as $index => [$level, $service, $message, $occurrences]) {
            LogEvent::create([
                'level' => $level,
                'service' => $service,
                'message' => $message,
                'occurrences' => $occurrences,
                'occurred_at' => now()->subMinutes(30 - $index),
            ]);
        }
    }
}
