<?php

namespace App\Monitoring\Checks\Common;

use App\Monitoring\Checks\BusinessCheck;
use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * 各サービスのキュー失敗ジョブ（*_failed_jobs）。前回 checkpoint 以降の新規失敗だけを例外種別ごとに集約して報告する。
 * ログ由来と同じく、静かになっただけでは自動復旧しない（担当者が復旧確認）。
 */
abstract class QueueFailedCheck extends BusinessCheck
{
    abstract protected function app(): string;

    abstract protected function table(): string;

    public function definition(): CheckDefinition
    {
        $code = strtolower($this->app());

        return new CheckDefinition($code.'.queue_failed', $this->app(), 'キュー失敗ジョブ', $this->table().' に新しい失敗ジョブが記録されていないか', 1, 600, 600, 'RB-07', false, false, 99, true, ['lookback_hours' => 24], ['lookback_hours']);
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        $table = preg_replace('/[^a-z_]/', '', $this->table());
        $since = $this->jst($ctx->nowUtc->subHours((int) $ctx->config('lookback_hours', 24)));
        $lastId = (int) ($ctx->previousCheckpoint['last_id'] ?? 0);
        $rows = $ctx->source()->select("SELECT id, queue, failed_at, SUBSTR(exception, 1, 600) AS exc FROM {$table} WHERE id > ? AND failed_at >= ? ORDER BY id LIMIT 500", [$lastId, $since]);
        $maxId = $lastId;
        $groups = [];
        foreach ($rows as $r) {
            $maxId = max($maxId, (int) $r->id);
            $k = $this->exceptionKey($r->exc);
            $groups[$k['key']] ??= ['class' => $k['class'], 'message' => $k['message'], 'count' => 0, 'queues' => [], 'last' => null, 'ids' => []];
            $groups[$k['key']]['count']++;
            $groups[$k['key']]['queues'][$r->queue] = true;
            $groups[$k['key']]['last'] = $r->failed_at;
            if (count($groups[$k['key']]['ids']) < 20) {
                $groups[$k['key']]['ids'][] = (int) $r->id;
            }
        }
        if ($lastId === 0 && ! $rows) {
            $maxId = (int) $ctx->source()->scalar("SELECT COALESCE(MAX(id), 0) FROM {$table}", [], 0);
        }
        $obs = [];
        foreach ($groups as $key => $g) {
            $obs[] = Observation::violation("キュー失敗ジョブ {$g['class']}: {$g['count']} 件（{$g['message']}）", ['fp' => $key, 'class' => $g['class']], null,
                ['new_failed' => $g['count']], ['queues' => array_keys($g['queues']), 'last_failed_at' => $g['last'], 'failed_job_ids' => $g['ids']], 'QUEUE_FAILED_'.strtoupper($g['class']));
        }
        if (! $obs) {
            $obs[] = Observation::healthy('新しい失敗ジョブはありません。', ['queue' => 'scan'], null, ['last_id' => $maxId]);
        }

        return new CheckResult($obs, null, true, ['last_id' => $maxId]);
    }
}
