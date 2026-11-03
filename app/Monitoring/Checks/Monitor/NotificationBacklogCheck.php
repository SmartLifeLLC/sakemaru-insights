<?php

namespace App\Monitoring\Checks\Monitor;

use App\Models\Monitoring\Notification;
use App\Monitoring\Checks\AbstractCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;
use Carbon\CarbonImmutable;

/** monitor.notification: 最古未送信5分超または恒久エラーで ERROR。 */
class NotificationBacklogCheck extends AbstractCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('monitor.notification', 'INSIGHTS', 'Slack通知の配送', 'エラー通知が期限内に配送されているか', 1, 300, 300, 'RB-09', false, false, 1, true, ['stale_seconds' => 300], ['stale_seconds']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $stale = (int) $ctx->config('stale_seconds', 300);
        $oldest = Notification::query()->whereIn('status', ['PENDING', 'RETRY_WAIT', 'SENDING'])->orderBy('id')->first(['id', 'created_at', 'status', 'last_error_code']);
        $blocked = Notification::query()->where('status', 'BLOCKED')->orderByDesc('id')->limit(5)->get(['id', 'last_http_status', 'last_error_code']);
        $obs = [];
        if ($blocked->isNotEmpty()) {
            $obs[] = Observation::violation('配送不可（BLOCKED）の通知が存在します。通知経路の設定を確認してください。', ['queue' => 'blocked'], null, ['blocked_sample' => $blocked->count()], ['samples' => $blocked->toArray()], 'MONITOR_NOTIFICATION_BLOCKED');
        } else {
            $obs[] = Observation::healthy('配送不可の通知はありません。', ['queue' => 'blocked']);
        }
        if ($oldest) {
            $age = (int) CarbonImmutable::instance($oldest->created_at)->diffInSeconds($ctx->nowUtc);
            if ($age > $stale) {
                $obs[] = Observation::violation('通知 N-'.$oldest->id.' が '.intdiv($age, 60).'分間未送信です（'.$oldest->status.' / '.($oldest->last_error_code ?: '-').'）。', ['queue' => 'pending'], null, ['oldest_age_seconds' => $age], [], 'MONITOR_NOTIFICATION_STALE');
            } else {
                $obs[] = Observation::healthy('未送信通知は '.$age.' 秒以内です。', ['queue' => 'pending'], null, ['oldest_age_seconds' => $age]);
            }
        } else {
            $obs[] = Observation::healthy('未送信の通知はありません。', ['queue' => 'pending']);
        }

        return new CheckResult($obs);
    }
}
