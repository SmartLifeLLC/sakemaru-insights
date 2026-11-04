<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\CheckAttempt;
use App\Models\Monitoring\CheckRun;
use App\Models\Monitoring\RecheckRequest;
use App\Monitoring\Contracts\Check;
use App\Monitoring\Enums\ExecutionStatus;
use App\Monitoring\Enums\Outcome;
use App\Monitoring\Enums\Severity;
use App\Monitoring\Enums\SourceKind;
use App\Monitoring\Exceptions\SourceBudgetExceeded;
use App\Monitoring\Exceptions\SourceUnavailable;
use App\Monitoring\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * 単一の監視実行器（仕様書 4.1 / 第7章 / 第16章）。
 * - 監視対象DBごとに owner 1ノードだけが実行する。同一ホスト内の多重起動は flock で防ぐ。
 * - 1回の起動は 90 秒、チェック1件は 45 秒を上限。SQL 時間予算を 5 分枠で管理する。
 * - 判定結果は CHECK イベントとして取込器へ渡す。画面リクエストからは起動しない。
 */
class CheckRunner
{
    public const UUID_NS = '2f1c6b9e-5d3a-4e7b-9c1d-0a8b7c6d5e4f';

    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly CheckRegistry $registry,
        private readonly SiteRegistry $sites,
        private readonly ObservationIngestor $ingestor,
        private readonly SelfMonitor $selfMonitor,
    ) {}

    /**
     * @param  string[]|null  $onlyKeys  "APP:check_key" で限定
     * @return array<string,mixed>
     */
    public function run(?CarbonImmutable $now = null, ?array $onlyKeys = null, bool $force = false, bool $ignoreOwner = false): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $stats = ['executed' => 0, 'skipped' => 0, 'failed' => 0, 'rechecks' => 0, 'timed_out_runs' => 0, 'message' => null];

        $owner = (string) config('monitoring.deployment.check_owner_node');
        if (! $ignoreOwner && $owner !== $this->sites->nodeCode()) {
            $stats['message'] = "このノード({$this->sites->nodeCode()})は照合 owner({$owner}) ではありません。実行を拒否しました。";

            return $stats;
        }
        if (! $this->acquireLock()) {
            $stats['message'] = '同じ配置の監視実行器が既に動作中です（flock）。';

            return $stats;
        }
        try {
            $this->sites->sync();
            $this->registry->sync($this->sites);
            $stats['timed_out_runs'] = $this->expireStaleRuns($now);

            $deadline = microtime(true) + (int) config('monitoring.budget.run_timeout_seconds', 90);
            $budget = $this->budgetWindow($now);
            $source = SourceReader::fromConfig(max(0, (int) config('monitoring.budget.sql_budget_ms_per_5min', 5000) - $budget['used_ms']));

            // 再確認予約を先に（期限超過の軽量確認、再発中エラーの再確認の優先順）
            $stats['rechecks'] = $this->processRechecks($now, $source, $deadline);

            $due = $this->dueChecks($now, $onlyKeys, $force);
            $insightsSiteId = (int) ($this->sites->site('INSIGHTS')?->id ?? 0);
            $sourceMissingNoted = false;
            foreach ($due as $model) {
                if (! $source->isConfigured() && (int) $model->site_id !== $insightsSiteId) {
                    // 業務DB接続が未設定: 業務照合は実行せず UNKNOWN のまま（HEALTHY にしない）。1回の起動で1件だけ記録。
                    if (! $sourceMissingNoted) {
                        $this->selfMonitor->record('MONITOR_SOURCE_NOT_CONFIGURED', Severity::WARNING, '業務DB接続（monitor_source）が未設定のため、業務照合チェックは実行していません。', ['resource' => 'monitor_source']);
                        $sourceMissingNoted = true;
                    }
                    $model->last_outcome = Outcome::UNKNOWN->value;
                    $model->next_due_at = $now->addSeconds((int) $model->interval_seconds);
                    $model->save();
                    $stats['skipped']++;

                    continue;
                }
                if (microtime(true) >= $deadline) {
                    $stats['skipped']++;

                    continue;
                }
                $site = $this->sites->siteById((int) $model->site_id);
                $check = $site ? $this->registry->find($site->application_code, $model->check_key) : null;
                if (! $check || ! $site) {
                    $stats['skipped']++;

                    continue;
                }
                $slot = intdiv($now->getTimestamp(), max(60, (int) $model->interval_seconds));
                $ok = $this->execute($model, $check, $source, $now, 'SCHEDULE', [], "{$model->id}:{$slot}:SCHEDULE");
                $ok === null ? $stats['skipped']++ : ($ok ? $stats['executed']++ : $stats['failed']++);
            }
            $this->saveBudget($budget, $source->queryMs());
        } finally {
            $this->releaseLock();
        }

        return $stats;
    }

    /**
     * @return bool|null true=成功 false=失敗 null=既に同じ枠が記録済み
     */
    public function execute(CheckModel $model, Check $check, SourceReader $source, CarbonImmutable $now, string $trigger, array $scope, string $executionKey, ?RecheckRequest $recheck = null): ?bool
    {
        $def = $check->definition();
        $site = $this->sites->siteById((int) $model->site_id);
        try {
            $run = CheckRun::query()->create([
                'check_id' => $model->id,
                'execution_key' => $executionKey,
                'trigger_type' => $trigger,
                'attempt_count' => 1,
                'scheduled_at' => $now,
                'started_at' => CarbonImmutable::now('UTC'),
                'execution_status' => ExecutionStatus::RUNNING->value,
                'expires_at' => $now->addDays((int) config('monitoring.retention.info_days', 30)),
            ]);
        } catch (QueryException) {
            return null; // 再起動で同じ定期枠を重複記録しない
        }
        $attempt = CheckAttempt::query()->create([
            'run_id' => $run->id, 'attempt_no' => 1, 'started_at' => $run->started_at,
            'execution_status' => ExecutionStatus::RUNNING->value, 'expires_at' => $run->expires_at,
        ]);

        $config = $this->registry->effectiveConfig($def, $model->config_json);
        // 前回成功時の checkpoint（新規失敗だけを数える監視項目で使う）
        $prev = CheckRun::query()->where('check_id', $model->id)->where('execution_status', ExecutionStatus::SUCCEEDED->value)
            ->whereNotNull('checkpoint_json')->orderByDesc('id')->value('checkpoint_json');
        $prev = is_string($prev) ? (json_decode($prev, true) ?: []) : (array) $prev;
        $context = new CheckContext($source, $now, $config, $scope, $trigger, $prev);
        $qBefore = [$source->queryCount(), $source->queryMs()];
        $startedAt = microtime(true);
        $timeout = (int) config('monitoring.budget.check_timeout_seconds', 45);

        try {
            $result = $check->evaluate($context);
            $elapsed = microtime(true) - $startedAt;
            $observations = $result->observations;
            if ($elapsed > $timeout) {
                // 子プロセス監視の代替: 上限超過は TIMED_OUT として記録し、結果は UNKNOWN に落とす
                $observations = [Observation::unknown("チェックが上限{$timeout}秒を超過しました（".round($elapsed, 1).'秒）。結果は採用しません。', $scope, $scope['business_date'] ?? null, [], 'CHECK_TIMED_OUT')];
                $this->finishRun($run, $attempt, $source, $qBefore, ExecutionStatus::TIMED_OUT, Outcome::UNKNOWN, 'CHECK_TIMED_OUT', $result->verificationComplete);
                $this->emit($model, $def, $run, $observations, $now);
                $this->recordFailure($model, $now, 'CHECK_TIMED_OUT');
                $recheck?->fill(['status' => 'FAILED', 'finished_at' => CarbonImmutable::now('UTC'), 'run_id' => $run->id, 'result_outcome' => 'UNKNOWN', 'message' => 'timeout'])->save();

                return false;
            }
            $worst = $this->worstOutcome($observations);
            $this->finishRun($run, $attempt, $source, $qBefore, ExecutionStatus::SUCCEEDED, $worst, null, $result->verificationComplete, $result->watermarkHash, $result->checkpoint);
            $this->emit($model, $def, $run, $observations, $now);
            if ((int) $model->consecutive_failures > 0) {
                // 連続失敗（休止）からの回復: 休止の自己監視案件を復旧にする
                foreach (['MONITOR_DB_BUDGET_EXCEEDED', 'MONITOR_SOURCE_UNAVAILABLE'] as $code) {
                    $this->selfMonitor->recovered($code, ['check' => $model->check_key], $model->check_key.' が正常に完了（連続失敗 '.$model->consecutive_failures.' 回から回復）');
                }
            }
            $model->consecutive_failures = 0;
            $model->last_completed_at = CarbonImmutable::now('UTC');
            $model->last_outcome = $worst->value;
            if ($trigger === 'SCHEDULE') {
                $model->next_due_at = $now->addSeconds((int) $model->interval_seconds);
            }
            $model->save();
            $recheck?->fill(['status' => 'DONE', 'finished_at' => CarbonImmutable::now('UTC'), 'run_id' => $run->id, 'result_outcome' => $worst->value, 'message' => $observations ? mb_substr($observations[0]->summary, 0, 500) : null])->save();

            return true;
        } catch (SourceBudgetExceeded $e) {
            $this->finishRun($run, $attempt, $source, $qBefore, ExecutionStatus::FAILED, Outcome::UNKNOWN, 'MONITOR_DB_BUDGET_EXCEEDED', false);
            $this->emit($model, $def, $run, [Observation::unknown('SQL時間予算を使い切ったため判定できませんでした。', $scope, $scope['business_date'] ?? null, [], 'MONITOR_DB_BUDGET_EXCEEDED')], $now);
            $this->recordFailure($model, $now, 'MONITOR_DB_BUDGET_EXCEEDED', $e->getMessage());
            $recheck?->fill(['status' => 'BUDGET_WAIT', 'finished_at' => CarbonImmutable::now('UTC'), 'run_id' => $run->id, 'message' => '予算待ち'])->save();

            return false;
        } catch (SourceUnavailable $e) {
            $this->finishRun($run, $attempt, $source, $qBefore, ExecutionStatus::FAILED, Outcome::UNKNOWN, 'MONITOR_SOURCE_UNAVAILABLE', false);
            $this->emit($model, $def, $run, [Observation::unknown('業務DB（monitor_source）へ接続できないため判定できませんでした。', $scope, $scope['business_date'] ?? null, [], 'MONITOR_SOURCE_UNAVAILABLE')], $now);
            $this->recordFailure($model, $now, 'MONITOR_SOURCE_UNAVAILABLE', $e->getMessage());
            $recheck?->fill(['status' => 'FAILED', 'finished_at' => CarbonImmutable::now('UTC'), 'run_id' => $run->id, 'message' => '業務DB接続不可'])->save();

            return false;
        } catch (\Throwable $e) {
            report($e);
            $this->finishRun($run, $attempt, $source, $qBefore, ExecutionStatus::FAILED, Outcome::UNKNOWN, 'CHECK_EXCEPTION', false);
            $this->emit($model, $def, $run, [Observation::unknown('チェック実行中に例外が発生しました: '.get_class($e), $scope, $scope['business_date'] ?? null, ['exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)], 'CHECK_EXCEPTION')], $now);
            $this->recordFailure($model, $now, 'CHECK_EXCEPTION', get_class($e));
            $recheck?->fill(['status' => 'FAILED', 'finished_at' => CarbonImmutable::now('UTC'), 'run_id' => $run->id, 'message' => get_class($e)])->save();

            return false;
        }
    }

    /** @param Observation[] $observations */
    private function emit(CheckModel $model, \App\Monitoring\Contracts\CheckDefinition $def, CheckRun $run, array $observations, CarbonImmutable $now): void
    {
        $events = [];
        $override = $model->rule_json['severity'] ?? null; // 監視ルールの重大度（VIOLATION のみ上書き）
        foreach (array_values($observations) as $i => $o) {
            $severity = ($o->outcome === Outcome::VIOLATION && in_array($override, ['WARNING', 'ERROR', 'CRITICAL'], true)) ? $override : $o->severity->value;
            $events[] = [
                'schema_version' => 1,
                'event_id' => \App\Monitoring\Support\Uuid::v5(self::UUID_NS, $run->execution_key.':'.$i),
                'application_code' => $o->applicationCode ?? $def->applicationCode,
                'node_code' => $this->sites->nodeCode(),
                'source_kind' => SourceKind::CHECK->value,
                'check_key' => $def->key,
                'check_version' => $def->version,
                'run_id' => $run->id,
                'source_sequence' => $run->id,
                'severity' => $severity,
                'outcome' => $o->outcome->value,
                'error_code' => $o->errorCode,
                'business_date' => $o->businessDate,
                'scope' => $o->scope,
                'occurred_at' => $now->toIso8601String(),
                'summary' => $o->summary,
                'metrics' => $o->metrics,
                'evidence' => $o->evidence,
                'source_watermark' => $o->watermark,
                'verification_complete' => $o->verificationComplete,
                'runbook_key' => $def->runbookKey,
            ];
        }
        if ($events) {
            $this->ingestor->ingestInternal($events, 'checker');
        }
    }

    private function finishRun(CheckRun $run, CheckAttempt $attempt, SourceReader $source, array $qBefore, ExecutionStatus $status, Outcome $outcome, ?string $failureCode, bool $complete, ?string $watermark = null, array $checkpoint = []): void
    {
        $finished = CarbonImmutable::now('UTC');
        $qc = $source->queryCount() - $qBefore[0];
        $qm = $source->queryMs() - $qBefore[1];
        $run->fill([
            'finished_at' => $finished, 'execution_status' => $status->value, 'outcome' => $outcome->value,
            'query_count' => $qc, 'query_ms' => $qm, 'max_query_ms' => $source->maxQueryMs(),
            'watermark_hash' => $watermark ? hash('sha256', $watermark) : null, 'verification_complete' => $complete,
            'checkpoint_json' => $checkpoint ?: null, 'failure_code' => $failureCode,
        ])->save();
        $attempt->fill(['finished_at' => $finished, 'execution_status' => $status->value, 'failure_code' => $failureCode, 'query_count' => $qc, 'query_ms' => $qm])->save();
    }

    private function recordFailure(CheckModel $model, CarbonImmutable $now, string $code, ?string $detail = null): void
    {
        $model->consecutive_failures += 1;
        $model->last_outcome = Outcome::UNKNOWN->value;
        $model->next_due_at = $now->addSeconds((int) $model->interval_seconds);
        $threshold = (int) config('monitoring.budget.consecutive_failures_before_backoff', 3);
        if ($model->consecutive_failures >= $threshold) {
            $backoffs = (array) config('monitoring.budget.backoff_minutes', [15, 30, 60]);
            $idx = min($model->consecutive_failures - $threshold, count($backoffs) - 1);
            $model->paused_until = $now->addMinutes((int) $backoffs[$idx]);
            $model->pause_reason = $code;
            $site = $this->sites->siteById((int) $model->site_id);
            $this->selfMonitor->record($code === 'MONITOR_SOURCE_UNAVAILABLE' ? 'MONITOR_SOURCE_UNAVAILABLE' : 'MONITOR_DB_BUDGET_EXCEEDED', Severity::ERROR,
                sprintf('%s の %s が%d回連続で失敗（%s）。%d分休止します。次回: %s', $site?->application_code, $model->check_key, $model->consecutive_failures, $code, (int) $backoffs[$idx], $model->paused_until->setTimezone('Asia/Tokyo')->format('H:i')),
                ['check' => $model->check_key], ['detail' => $detail ? mb_substr($detail, 0, 300) : null], ['consecutive_failures' => $model->consecutive_failures], 'RB-08');
        }
        $model->save();
    }

    /** @param Observation[] $observations */
    private function worstOutcome(array $observations): Outcome
    {
        $rank = ['VIOLATION' => 5, 'UNKNOWN' => 4, 'WARNING' => 3, 'HEALTHY' => 2, 'NOT_DUE' => 1, 'DISABLED' => 0];
        $worst = Outcome::HEALTHY;
        $best = -1;
        foreach ($observations as $o) {
            if ($rank[$o->outcome->value] > $best) {
                $best = $rank[$o->outcome->value];
                $worst = $o->outcome;
            }
        }

        return $observations ? $worst : Outcome::UNKNOWN;
    }

    /** @return \Illuminate\Support\Collection<int,CheckModel> サイト巡回で公平に並べた実行対象 */
    private function dueChecks(CarbonImmutable $now, ?array $onlyKeys, bool $force): \Illuminate\Support\Collection
    {
        $q = CheckModel::query()->where('enabled', true)->where('implemented', true);
        if (! $force) {
            $q->where(fn ($w) => $w->whereNull('next_due_at')->orWhere('next_due_at', '<=', $now))
                ->where(fn ($w) => $w->whereNull('paused_until')->orWhere('paused_until', '<=', $now));
        }
        $rows = $q->orderBy('priority')->orderBy('next_due_at')->get();
        // 3 段ゲート（サービス → 機能 → 監視項目）。無効なものは実行しない（仕様書 v2 4.1）
        $features = app(FeatureRegistry::class);
        $features->forget();
        $rows = $rows->filter(fn ($m) => $features->gate($m)['active'])->values();
        if ($onlyKeys) {
            $rows = $rows->filter(function ($m) use ($onlyKeys) {
                $site = $this->sites->siteById((int) $m->site_id);

                return $site && in_array($site->application_code.':'.$m->check_key, $onlyKeys, true);
            });
        }
        // INSIGHTS 自己監視を先頭に、以降はサイトごとの巡回（同じサイトだけで独占しない）
        $insights = $this->sites->site('INSIGHTS');
        $self = $rows->filter(fn ($m) => $insights && (int) $m->site_id === (int) $insights->id)->values();
        $others = $rows->reject(fn ($m) => $insights && (int) $m->site_id === (int) $insights->id)->groupBy('site_id')->map->values();
        $ordered = $self->all();
        $max = $others->max(fn ($g) => $g->count()) ?? 0;
        for ($i = 0; $i < $max; $i++) {
            foreach ($others as $g) {
                if (isset($g[$i])) {
                    $ordered[] = $g[$i];
                }
            }
        }

        return collect($ordered);
    }

    private function processRechecks(CarbonImmutable $now, SourceReader $source, float $deadline): int
    {
        $n = 0;
        $minInterval = (int) config('monitoring.budget.min_recheck_interval_seconds', 300);
        $rows = RecheckRequest::query()->whereIn('status', ['QUEUED', 'BUDGET_WAIT'])->orderBy('id')->limit(20)->get();
        foreach ($rows as $req) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $model = CheckModel::query()->find($req->check_id);
            $site = $model ? $this->sites->siteById((int) $model->site_id) : null;
            $check = $site ? $this->registry->find($site->application_code, $model->check_key) : null;
            if (! $model || ! $check) {
                $req->fill(['status' => 'FAILED', 'finished_at' => $now, 'message' => 'check_not_found'])->save();

                continue;
            }
            $recent = CheckRun::query()->where('check_id', $model->id)->where('started_at', '>=', $now->subSeconds($minInterval))->exists();
            if ($recent && $req->status === 'QUEUED') {
                $req->fill(['status' => 'BUDGET_WAIT', 'message' => "同一対象は最短{$minInterval}秒間隔です。次回実行で処理します。"])->save();

                continue;
            }
            $req->fill(['status' => 'RUNNING', 'started_at' => CarbonImmutable::now('UTC')])->save();
            $this->execute($model, $check, $source, $now, 'RECHECK', (array) $req->scope_json, "{$model->id}:recheck:{$req->request_id}", $req);
            $n++;
        }

        return $n;
    }

    private function expireStaleRuns(CarbonImmutable $now): int
    {
        $limit = $now->subSeconds((int) config('monitoring.budget.run_timeout_seconds', 90) * 2);
        $stale = CheckRun::query()->where('execution_status', ExecutionStatus::RUNNING->value)->where('started_at', '<', $limit)->limit(100)->get();
        foreach ($stale as $run) {
            $run->fill(['execution_status' => ExecutionStatus::TIMED_OUT->value, 'outcome' => Outcome::UNKNOWN->value, 'finished_at' => $now, 'failure_code' => 'ORPHANED_RUN'])->save();
            CheckAttempt::query()->where('run_id', $run->id)->where('execution_status', ExecutionStatus::RUNNING->value)->update(['execution_status' => ExecutionStatus::TIMED_OUT->value, 'finished_at' => $now, 'failure_code' => 'ORPHANED_RUN']);
        }

        return $stale->count();
    }

    // ---- flock / budget state -------------------------------------------------

    private function stateDir(): string
    {
        $dir = rtrim((string) config('monitoring.collector.state_dir'), '/');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    private function acquireLock(): bool
    {
        $path = $this->stateDir().'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->sites->deploymentId()).'.checks.lock';
        $fh = @fopen($path, 'c');
        if (! $fh) {
            return true; // ロックファイルを作れない環境ではそのまま実行（systemd 側の制御を前提）
        }
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        $this->lock = $fh;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lock) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** @return array{window_start:int,used_ms:int,path:string} */
    private function budgetWindow(CarbonImmutable $now): array
    {
        $path = $this->stateDir().'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->sites->deploymentId()).'.sql-budget.json';
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $windowStart = intdiv($now->getTimestamp(), 300) * 300;
        if (! is_array($state) || (int) ($state['window_start'] ?? 0) !== $windowStart) {
            $state = ['window_start' => $windowStart, 'used_ms' => 0];
        }

        return ['window_start' => $windowStart, 'used_ms' => (int) $state['used_ms'], 'path' => $path];
    }

    private function saveBudget(array $window, int $usedMs): void
    {
        @file_put_contents($window['path'], CanonicalJson::encode(['window_start' => $window['window_start'], 'used_ms' => $window['used_ms'] + $usedMs]), LOCK_EX);
    }
}
