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
        return new CheckDefinition('monitor.notification', 'INSIGHTS', 'Slack通知の配送', 'エラー通知が期限内に配送されているか', 1, 300, 300, 'RB-09', false, false, 1, true, ['stale_seconds' => 300, 'blocked_window_seconds' => 3600], ['stale_seconds', 'blocked_window_seconds']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $stale = (int) $ctx->config('stale_seconds', 300);
        // 「未送信」は送れる状態（available_at 到来）になってからの経過で測る。電話の猶予（作成から 5 分待って発信）や再試行の待機は遅延ではない
        $oldest = Notification::query()->whereIn('status', ['PENDING', 'RETRY_WAIT', 'SENDING'])
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $ctx->nowUtc))
            ->orderByRaw('COALESCE(available_at, created_at)')->first(['id', 'created_at', 'available_at', 'status', 'last_error_code']);
        // 配送不可は直近の分だけを見る（経路を設定した後に、過去の配送不可が残っていても案件が消えないのを防ぐ）
        $window = (int) $ctx->config('blocked_window_seconds', 3600);
        $blocked = Notification::query()->where('status', 'BLOCKED')->where('created_at', '>=', $ctx->nowUtc->subSeconds($window))->orderByDesc('id')->limit(5)->get(['id', 'last_http_status', 'last_error_code']);
        $obs = [];
        if ($blocked->isNotEmpty()) {
            $obs[] = Observation::violation('直近 '.intdiv($window, 60).' 分に配送不可（BLOCKED）になった通知があります。通知経路の設定を確認してください。', ['queue' => 'blocked'], null, ['blocked_sample' => $blocked->count()], ['samples' => $blocked->toArray()], 'MONITOR_NOTIFICATION_BLOCKED');
        } else {
            $obs[] = Observation::healthy('配送不可の通知はありません。', ['queue' => 'blocked']);
        }
        if ($oldest) {
            $since = $oldest->available_at ? max(CarbonImmutable::instance($oldest->available_at), CarbonImmutable::instance($oldest->created_at)) : CarbonImmutable::instance($oldest->created_at);
            $age = (int) $since->diffInSeconds($ctx->nowUtc);
            if ($age > $stale) {
                $obs[] = Observation::violation('通知 N-'.$oldest->id.' が '.intdiv($age, 60).'分間未送信です（'.($oldest->status instanceof \BackedEnum ? $oldest->status->value : (string) $oldest->status).' / '.($oldest->last_error_code ?: '-').'）。', ['queue' => 'pending'], null, ['oldest_age_seconds' => $age], [], 'MONITOR_NOTIFICATION_STALE');
            } else {
                $obs[] = Observation::healthy('未送信通知は '.$age.' 秒以内です。', ['queue' => 'pending'], null, ['oldest_age_seconds' => $age]);
            }
        } else {
            $obs[] = Observation::healthy('未送信の通知はありません。', ['queue' => 'pending']);
        }

        return new CheckResult($obs);
    }
}
