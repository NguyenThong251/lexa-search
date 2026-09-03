<?php

namespace Lexa\Wp;

/**
 * Drains pending index jobs through Action Scheduler's runner (so AS claim
 * locking is reused — no separate mutex). This is the explicit, reliable path
 * used by `wp lexa run`, the admin "Process pending now" button, and a server
 * cron — guaranteed to work even when AS's async loopback is blocked or there
 * is no admin traffic. AS's own async runner still drains in the background too.
 */
final class Drainer
{
    public const LAST_DRAIN_OPTION = 'lexa_last_drain';

    public static function pendingCount(): int
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        $ids = as_get_scheduled_actions([
            'hook'     => SyncSubscriber::HOOK,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'group'    => SyncSubscriber::GROUP,
            'per_page' => 5000,
        ], 'ids');
        return is_array($ids) ? count($ids) : 0;
    }

    public static function run(int $limit = 100): int
    {
        if (!class_exists('ActionScheduler') || !function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        $ids = as_get_scheduled_actions([
            'hook'     => SyncSubscriber::HOOK,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'group'    => SyncSubscriber::GROUP,
            'per_page' => $limit,
            'orderby'  => 'date',
            'order'    => 'ASC',
        ], 'ids');

        if (!$ids) {
            return 0;
        }
        $runner = \ActionScheduler::runner();
        $done = 0;
        foreach ($ids as $actionId) {
            try {
                $runner->process_action((int) $actionId, 'Lexa');
                $done++;
            } catch (\Throwable $e) {
                // leave the action for retry; don't abort the batch
            }
        }
        if ($done > 0) {
            update_option(self::LAST_DRAIN_OPTION, time());
        }
        return $done;
    }

    public static function lastDrainAt(): int
    {
        return (int) get_option(self::LAST_DRAIN_OPTION, 0);
    }

    /** Stalled = work waiting, and nothing has drained recently. */
    public static function isStalled(int $thresholdSeconds = 600): bool
    {
        if (self::pendingCount() < 1) {
            return false;
        }
        $last = self::lastDrainAt();
        return ($last === 0) || ((time() - $last) > $thresholdSeconds);
    }
}
