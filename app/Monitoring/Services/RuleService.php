<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Check as CheckModel;
use App\Models\Monitoring\Node;
use App\Monitoring\Resources\ThresholdEvaluator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * 監視ルールの変更（v2 第4.3 / V-111〜113 / V-169）。許可された項目だけを保存し、理由必須・監査あり。
 * 業務DB には触れない。
 */
class RuleService
{
    public const SEVERITIES = ['WARNING', 'ERROR', 'CRITICAL'];

    public const ACTIONS = ['notify', 'record_only'];

    public function __construct(private readonly CheckRegistry $checks, private readonly FeatureRegistry $features, private readonly SiteRegistry $sites, private readonly AuditService $audit) {}

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 2) {
            throw new \InvalidArgumentException('変更理由を入力してください。');
        }
    }

    /**
     * @param  array{enabled?:bool,interval_seconds?:int,grace_seconds?:int,severity?:?string,action?:string,config?:array}  $data
     */
    public function update(CheckModel $check, array $data, array $actor, string $reason): CheckModel
    {
        $this->requireReason($reason);
        $site = $this->sites->siteById((int) $check->site_id);
        $def = $site ? ($this->checks->definition($site->application_code, $check->check_key) ?? $this->checks->definitionByKey($check->check_key)) : null;
        if (! $def) {
            throw new \InvalidArgumentException('監視項目の定義が見つかりません。');
        }
        $before = $this->snapshot($check);
        $rule = (array) $check->rule_json;
        if (array_key_exists('severity', $data)) {
            $sev = $data['severity'] ?: null;
            if ($sev !== null && ! in_array($sev, self::SEVERITIES, true)) {
                throw new \InvalidArgumentException('重大度が不正です。');
            }
            $sev === null ? Arr::forget($rule, 'severity') : $rule['severity'] = $sev;
        }
        if (array_key_exists('action', $data)) {
            if (! in_array($data['action'], self::ACTIONS, true)) {
                throw new \InvalidArgumentException('一致時の動作が不正です。');
            }
            $rule['action'] = $data['action'];
        }
        if (array_key_exists('interval_seconds', $data)) {
            $iv = (int) $data['interval_seconds'];
            if ($iv < 60 || $iv > 86400) {
                throw new \InvalidArgumentException('間隔は 60 秒〜24 時間で指定してください。');
            }
            $rule['interval_seconds'] = $iv;
            $check->interval_seconds = $iv;
        }
        if (array_key_exists('grace_seconds', $data)) {
            $g = (int) $data['grace_seconds'];
            if ($g < 0 || $g > 86400) {
                throw new \InvalidArgumentException('猶予は 0 秒〜24 時間で指定してください。');
            }
            $check->grace_seconds = $g;
        }
        if (array_key_exists('config', $data)) {
            $allowed = [];
            foreach ((array) $data['config'] as $k => $v) {
                if (in_array($k, $def->editableConfigKeys, true)) {
                    $allowed[$k] = self::validateConfigValue((string) $k, $v);
                }
            }
            $check->config_json = array_merge((array) $check->config_json, $allowed) ?: null;
        }
        $check->rule_json = $rule ?: null;
        $check->updated_user_id = $actor['id'] ?? null;
        $check->updated_name = $actor['name'] ?? null;
        $check->reason = $reason;
        DB::connection('monitor_store')->transaction(function () use ($check, $data, $actor, $reason, $def) {
            $check->save();
            if (array_key_exists('enabled', $data) && (bool) $data['enabled'] !== (bool) $check->enabled) {
                if ($data['enabled'] && ! $def->implemented) {
                    throw new \InvalidArgumentException('未実装の監視項目は有効化できません。');
                }
                $this->features->setCheckEnabled($check, (bool) $data['enabled'], $actor, $reason);
            }
        });
        $this->audit->record($actor, 'check.rule.update', 'check', ($this->sites->siteById((int) $check->site_id)?->application_code ?? '?').':'.$check->check_key, $before, $this->snapshot($check->fresh()), $reason);

        return $check->fresh();
    }

    /** 優先順位を 1 つ上げる / 下げる（同じサービス内）。CheckRunner の実行順に反映される */
    public function move(CheckModel $check, string $dir, array $actor): void
    {
        $list = CheckModel::query()->where('site_id', $check->site_id)->orderBy('priority')->orderBy('id')->get()->values();
        $i = $list->search(fn ($c) => (int) $c->id === (int) $check->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i === false || $j < 0 || $j >= $list->count()) {
            return;
        }
        DB::connection('monitor_store')->transaction(function () use ($list, $i, $j) {
            $ids = $list->pluck('id')->all();
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $k => $id) {
                CheckModel::query()->whereKey($id)->update(['priority' => ($k + 1) * 10]);
            }
        });
        $this->audit->record($actor, 'check.priority', 'check', (string) $check->check_key, ['position' => $i + 1], ['position' => $j + 1], '優先順位の変更');
    }

    /** ノード: 画面で変えられるのは表示名・有効/無効・しきい値だけ（node_code は .env 固定） */
    public function updateNode(Node $node, array $data, array $actor, string $reason): Node
    {
        $this->requireReason($reason);
        $before = ['display_name' => $node->display_name, 'enabled' => (bool) $node->enabled, 'thresholds' => $node->thresholds_json];
        if (array_key_exists('display_name', $data)) {
            $name = trim((string) $data['display_name']);
            if ($name === '' || mb_strlen($name) > 120) {
                throw new \InvalidArgumentException('表示名を入力してください。');
            }
            $node->display_name = $name;
        }
        if (array_key_exists('enabled', $data)) {
            $node->enabled = (bool) $data['enabled'];
        }
        if (array_key_exists('thresholds', $data)) {
            $node->thresholds_json = self::validateThresholds((array) $data['thresholds']) ?: null;
        }
        $node->updated_user_id = $actor['id'] ?? null;
        $node->updated_name = $actor['name'] ?? null;
        $node->reason = $reason;
        $node->save();
        $this->audit->record($actor, 'node.update', 'node', $node->node_code, $before, ['display_name' => $node->display_name, 'enabled' => (bool) $node->enabled, 'thresholds' => $node->thresholds_json], $reason);

        return $node;
    }

    /** しきい値の上書きを検証（既知の指標・数値・0〜100 等）。空欄は既定値を使う */
    public static function validateThresholds(array $in): array
    {
        $known = (array) config('monitoring.resources.thresholds', []);
        $out = [];
        foreach ($in as $metric => $vals) {
            if (! isset($known[$metric]) || ! is_array($vals)) {
                continue;
            }
            foreach ($vals as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                if ($k === 'disabled') {
                    if ($v) {
                        $out[$metric]['disabled'] = true;
                    }

                    continue;
                }
                if (! in_array($k, ['warning', 'error', 'critical', 'duration'], true) || ! is_numeric($v)) {
                    throw new \InvalidArgumentException(ThresholdEvaluator::LABELS[$metric].' のしきい値が不正です。');
                }
                $v = (float) $v;
                $max = ($k === 'duration' || $metric === 'process_missing') ? 3600 : 100;
                if ($v < 0 || $v > $max) {
                    throw new \InvalidArgumentException(ThresholdEvaluator::LABELS[$metric].' のしきい値が範囲外です。');
                }
                $out[$metric][$k] = $k === 'duration' ? (int) $v : $v;
            }
        }

        return $out;
    }

    private function snapshot(CheckModel $c): array
    {
        return ['enabled' => (bool) $c->enabled, 'interval_seconds' => (int) $c->interval_seconds, 'grace_seconds' => (int) $c->grace_seconds, 'rule' => $c->rule_json, 'config' => $c->config_json, 'priority' => (int) $c->priority];
    }

    /**
     * 設定値の型・形式をキーの命名規則で検証する（不正値でチェックが毎回例外になり休止するのを防ぐ。V-111）。
     *  - slots: ['02:00', ...] または ['mon' => ['13:30'], 'sun' => '23:35', 'daily' => [...]]
     *  - *_time / deadline_time: 'HH:MM'
     *  - *_minutes / *_hours / *_seconds / *_days / *_ms / min_* / *_after_failures: 0 以上の整数（上限あり）
     *  - ratio: 0 < x <= 100
     *  - strict_metrics / unconfirmed_warning（真偽値キー）: bool
     *  - department_sync_except / exclude_partners: 文字列の配列
     */
    public static function validateConfigValue(string $key, mixed $v): mixed
    {
        $hhmm = fn ($t) => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        $bad = fn (string $why) => throw new \InvalidArgumentException("設定 {$key} が不正です（{$why}）。");
        if ($key === 'slots' || str_starts_with($key, 'slots_')) {
            if (! is_array($v) || $v === []) {
                $bad('HH:MM の配列、または曜日ごとの配列');
            }
            $days = ['daily', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            if (array_is_list($v)) {
                foreach ($v as $t) {
                    $hhmm($t) || $bad('HH:MM 形式: '.json_encode($t));
                }

                return array_values(array_unique(array_map('strval', $v)));
            }
            $out = [];
            foreach ($v as $d => $times) {
                in_array(strtolower((string) $d), $days, true) || $bad('曜日キー: '.$d);
                $times = array_values((array) $times);
                foreach ($times as $t) {
                    $hhmm($t) || $bad('HH:MM 形式: '.json_encode($t));
                }
                $out[strtolower((string) $d)] = array_map('strval', $times);
            }

            return $out;
        }
        if (str_ends_with($key, '_time')) {
            $hhmm($v) || $bad('HH:MM 形式');

            return (string) $v;
        }
        if (in_array($key, ['strict_metrics', 'unconfirmed_warning'], true) || str_starts_with($key, 'is_') || str_starts_with($key, 'enable')) {
            if (! is_bool($v) && ! in_array($v, [0, 1, '0', '1', 'true', 'false'], true)) {
                $bad('true / false');
            }

            return in_array($v, [true, 1, '1', 'true'], true);
        }
        if (in_array($key, ['department_sync_except', 'exclude_partners'], true)) {
            is_array($v) || $bad('文字列の配列');

            return array_values(array_map(fn ($x) => mb_substr((string) $x, 0, 64), $v));
        }
        if ($key === 'ratio') {
            (is_numeric($v) && (float) $v > 0 && (float) $v <= 100) || $bad('0 より大きく 100 以下の数値');

            return (float) $v;
        }
        if (preg_match('/(_minutes|_hours|_seconds|_days|_ms|_count|_after_failures|_deliveries|_for_deposit)$/', $key) || str_starts_with($key, 'min_')) {
            (is_numeric($v) && (float) $v >= 0 && (float) $v == (int) $v) || $bad('0 以上の整数');
            $max = match (true) {
                str_ends_with($key, '_days') => 366,
                str_ends_with($key, '_hours') => 24 * 60,
                str_ends_with($key, '_minutes') => 60 * 24 * 7,
                str_ends_with($key, '_seconds') => 86400 * 7,
                default => 10_000_000,
            };
            (int) $v <= $max || $bad("上限 {$max}");

            return (int) $v;
        }
        // 未知の形式: スカラーだけ許可（配列・オブジェクトは拒否）
        is_scalar($v) || $v === null || $bad('単一の値');

        return $v;
    }

    /**
     * ノード登録の削除（名前変更前の残骸など）。資源データが 10 分以上届いていないノードだけ削除できる（受信中なら次の送信で再登録されるため）。
     * そのノードの未解決インシデント（node.resources / metrics_stale）は「ノード登録の削除」として終了し、通知を取り消す。監査に残す。
     */
    public function deleteNode(Node $node, array $actor, string $reason): void
    {
        $this->requireReason($reason);
        $now = \Carbon\CarbonImmutable::now('UTC');
        if ($node->last_heartbeat_at && \Carbon\CarbonImmutable::instance($node->last_heartbeat_at)->gt($now->subMinutes(10))) {
            throw new \InvalidArgumentException('このノードは 10 分以内に資源データを送っています。各サーバーの .env（MONITOR_NODE_CODE）を確認し、送信が止まってから削除してください。');
        }
        $code = $node->node_code;
        DB::connection('monitor_store')->transaction(function () use ($node, $code, $actor, $reason, $now) {
            $incidents = \App\Models\Monitoring\Incident::query()
                ->whereIn('workflow_status', \App\Monitoring\Enums\WorkflowStatus::unresolvedValues())
                ->where(fn ($q) => $q->where('scope_json->node', $code)->orWhere('scope_json->node_code', $code))
                ->get();
            $incidentService = app(IncidentService::class);
            foreach ($incidents as $i) {
                $before = ['workflow_status' => $i->workflow_status->value, 'observation_state' => $i->observation_state->value];
                $i->observation_state = \App\Monitoring\Enums\ObservationState::RECOVERED;
                $i->recovered_at = $i->recovered_at ?? $now;
                $i->workflow_status = \App\Monitoring\Enums\WorkflowStatus::CLOSED;
                $i->resolved_at = $i->resolved_at ?? $now;
                $i->closed_at = $now;
                $i->next_reminder_at = null;
                $i->resolution_json = ['cause_category' => 'node_removed', 'action_taken' => 'ノード登録 '.$code.' を削除', 'verification' => $reason, 'resolved_by' => $actor['name'] ?? null];
                $i->save();
                app(NotificationQueue::class)->cancelPending($i, 'node_removed');
                $incidentService->event($i, 'node_removed', $actor, $reason, $before, ['workflow_status' => 'CLOSED', 'observation_state' => 'RECOVERED'], 'node_removed:'.$code.':'.$i->id);
            }
            \App\Models\Monitoring\ServerMetric::query()->where('node_id', $node->id)->delete();
            $node->delete();
            $this->audit->record($actor, 'node.delete', 'node', $code, ['display_name' => $node->display_name, 'role' => $node->role, 'last_heartbeat_at' => $node->last_heartbeat_at?->format('c')], ['closed_incidents' => $incidents->count()], $reason);
        });
    }
}
