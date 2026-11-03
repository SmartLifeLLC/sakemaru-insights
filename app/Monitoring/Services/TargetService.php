<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Target;
use App\Monitoring\Enums\TargetKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 監視対象（ログ・HTTP プローブ）の登録・変更（v2 第4.2 / V-053）。
 * URL は顧客の自サービス（allowed_hosts）だけ。変更は理由必須で監査に残す。
 */
class TargetService
{
    public function __construct(private readonly SiteRegistry $sites, private readonly AuditService $audit, private readonly FeatureRegistry $features) {}

    /** 許可されない URL は例外。正規化した URL（フラグメント除去）を返す。 */
    public static function validateProbeUrl(string $url): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if (! $p || empty($p['scheme']) || empty($p['host'])) {
            throw new \InvalidArgumentException('URL の形式が正しくありません。');
        }
        $scheme = strtolower($p['scheme']);
        if (! in_array($scheme, config('monitoring.probe.allow_http') ? ['https', 'http'] : ['https'], true)) {
            throw new \InvalidArgumentException('https の URL のみ登録できます。');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('URL に認証情報を含めないでください。');
        }
        if (! self::hostAllowed(strtolower($p['host']))) {
            throw new \InvalidArgumentException('許可されていないホストです（MONITOR_PROBE_ALLOWED_HOSTS に顧客の自サービスを登録してください）。');
        }
        $path = $p['path'] ?? '/';

        return $scheme.'://'.strtolower($p['host']).(isset($p['port']) ? ':'.$p['port'] : '').$path.(isset($p['query']) ? '?'.$p['query'] : '');
    }

    public static function hostAllowed(string $host): bool
    {
        foreach ((array) config('monitoring.probe.allowed_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return true;
            }
        }

        return false;
    }

    /** @param array{id:?int,name:string} $actor */
    public function createProbe(string $applicationCode, string $displayName, string $url, array $actor, string $reason, int $expectStatus = 200): Target
    {
        $this->requireReason($reason);
        $site = $this->sites->site(strtoupper($applicationCode));
        if (! $site || $site->application_code === 'INSIGHTS') {
            throw new \InvalidArgumentException('対象サービスが不正です。');
        }
        $url = self::validateProbeUrl($url);
        if ($expectStatus < 100 || $expectStatus > 599) {
            throw new \InvalidArgumentException('期待するステータスが不正です。');
        }

        return DB::connection('monitor_store')->transaction(function () use ($site, $displayName, $url, $actor, $reason, $expectStatus) {
            $t = Target::query()->create([
                'kind' => TargetKind::HTTP_PROBE->value, 'site_id' => $site->id, 'node_id' => null,
                'display_name' => mb_substr(trim($displayName) ?: $site->display_name.' /up', 0, 120),
                'params_json' => ['url' => $url, 'method' => 'GET', 'expect_status' => $expectStatus],
                'enabled' => true, 'version' => 1,
                'updated_user_id' => $actor['id'] ?? null, 'updated_name' => $actor['name'] ?? null, 'reason' => $reason,
            ]);
            $this->audit->record($actor, 'target.create', 'target', (string) $t->id, null, $this->snapshot($t), $reason);
            // URL を登録した = そのサービスで HTTP 死活を使う。機能が無効なら同じ理由で有効化する（監査に残る）
            $fk = $this->features->featureForTarget($site->application_code, TargetKind::HTTP_PROBE->value);
            $feature = $fk ? $this->features->feature((int) $site->id, $fk) : null;
            if ($feature && ! $feature->enabled) {
                $this->features->setEnabled($feature, true, $actor, $reason.'（HTTP プローブ登録に伴う有効化）');
            }

            return $t;
        });
    }

    /** @param array<string,mixed> $changes display_name / url / expect_status / thresholds */
    public function update(Target $target, array $changes, array $actor, string $reason): Target
    {
        $this->requireReason($reason);
        $before = $this->snapshot($target);
        $params = (array) $target->params_json;
        if (array_key_exists('url', $changes)) {
            $params['url'] = self::validateProbeUrl((string) $changes['url']);
        }
        if (array_key_exists('expect_status', $changes)) {
            $params['expect_status'] = (int) $changes['expect_status'];
        }
        if (array_key_exists('display_name', $changes)) {
            $target->display_name = mb_substr(trim((string) $changes['display_name']), 0, 120);
        }
        if (array_key_exists('thresholds', $changes)) {
            $target->thresholds_json = $changes['thresholds'] ?: null;
        }
        $target->params_json = $params;
        $target->version += 1;
        $target->updated_user_id = $actor['id'] ?? null;
        $target->updated_name = $actor['name'] ?? null;
        $target->reason = $reason;
        $target->save();
        $this->audit->record($actor, 'target.update', 'target', (string) $target->id, $before, $this->snapshot($target), $reason);

        return $target;
    }

    public function setEnabled(Target $target, bool $enabled, array $actor, string $reason): Target
    {
        $this->requireReason($reason);
        $before = $this->snapshot($target);
        $target->enabled = $enabled;
        $target->updated_user_id = $actor['id'] ?? null;
        $target->updated_name = $actor['name'] ?? null;
        $target->reason = $reason;
        $target->updated_at = CarbonImmutable::now('UTC');
        $target->save();
        $this->audit->record($actor, $enabled ? 'target.enable' : 'target.disable', 'target', (string) $target->id, $before, $this->snapshot($target), $reason);

        return $target;
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 2) {
            throw new \InvalidArgumentException('変更理由を入力してください。');
        }
    }

    private function snapshot(Target $t): array
    {
        return ['kind' => $t->kind, 'site_id' => $t->site_id, 'display_name' => $t->display_name, 'params' => $t->params_json, 'enabled' => (bool) $t->enabled, 'thresholds' => $t->thresholds_json];
    }
}
