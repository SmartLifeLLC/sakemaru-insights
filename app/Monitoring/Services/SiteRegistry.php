<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Collector;
use App\Models\Monitoring\Site;
use App\Monitoring\Enums\Coverage;
use Illuminate\Support\Collection;

/**
 * 同じDBを利用するサイト（アプリ）・収集器の台帳（仕様書 4.1）。
 * 任意接続先を UI で登録させない。設定 → DB の同期だけを行う。
 */
class SiteRegistry
{
    /** @var array<string,Site>|null */
    private ?array $cache = null;

    public function deploymentId(): string
    {
        return (string) config('monitoring.deployment.id');
    }

    public function clientName(): string
    {
        return (string) config('monitoring.deployment.client_name');
    }

    public function environment(): string
    {
        return (string) config('monitoring.deployment.environment');
    }

    public function environmentLabel(): string
    {
        return match ($this->environment()) {
            'production', 'prd', 'prod' => '本番',
            'staging', 'stg' => 'STG',
            default => 'LOCAL',
        };
    }

    public function isProduction(): bool
    {
        return in_array($this->environment(), ['production', 'prd', 'prod'], true);
    }

    public function nodeCode(): string
    {
        return (string) config('monitoring.deployment.node_code', 'local');
    }

    /** config → DB 同期（冪等）。 */
    public function sync(): void
    {
        foreach ((array) config('monitoring.sites', []) as $code => $def) {
            $code = strtoupper((string) $code);
            $site = Site::query()->where('application_code', $code)->first();
            $attrs = [
                'display_name' => $def['display_name'] ?? $code,
                'owner_group' => $def['owner_group'] ?? null,
                'sort_order' => $def['sort'] ?? 100,
            ];
            if (! $site) {
                Site::query()->create(array_merge($attrs, [
                    'application_code' => $code,
                    'enabled' => true,
                    'coverage' => $code === 'INSIGHTS' ? Coverage::CONFIGURED : Coverage::NOT_CONFIGURED,
                    'coverage_json' => ['application_log' => false, 'business_checks' => false],
                ]));
            } else {
                $site->fill($attrs)->save();
            }
        }
        $this->cache = null;

        foreach ((array) config('monitoring.collectors', []) as $c) {
            if (empty($c['collector_uuid']) || empty($c['application_code']) || empty($c['node_code'])) {
                continue;
            }
            $site = $this->site(strtoupper($c['application_code']));
            if (! $site) {
                continue;
            }
            Collector::query()->updateOrCreate(
                ['collector_uuid' => $c['collector_uuid']],
                ['site_id' => $site->id, 'node_code' => $c['node_code'], 'credential_ref' => $c['secret_env'] ?? null],
            );
            $this->markCoverage($site, 'application_log', true);
        }
    }

    public function markCoverage(Site $site, string $kind, bool $value): void
    {
        $cov = $site->coverage_json ?? [];
        $cov[$kind] = $value;
        $site->coverage_json = $cov;
        unset($cov['noop']);
        $site->coverage_json = $cov;
        if (! $site->enabled) {
            $site->coverage = Coverage::DISABLED;
        } elseif ($site->application_code === 'INSIGHTS' || ($cov['application_log'] ?? false) || ($cov['business_checks'] ?? false) || ($cov['http_probe'] ?? false)) {
            $site->coverage = Coverage::CONFIGURED;
        } else {
            $site->coverage = Coverage::NOT_CONFIGURED;
        }
        $site->save();
        $this->cache = null;
    }

    /** @return Collection<int,Site> */
    public function all(): Collection
    {
        return collect($this->map())->sortBy('sort_order')->values();
    }

    /** @return array<string,Site> */
    public function map(): array
    {
        if ($this->cache === null) {
            $this->cache = Site::query()->get()->keyBy('application_code')->all();
        }

        return $this->cache;
    }

    public function site(string $applicationCode): ?Site
    {
        return $this->map()[strtoupper($applicationCode)] ?? null;
    }

    /** 日本語の表示名（例: 蔵）。未知コードはコードを返す。 */
    public function displayName(string $applicationCode): string
    {
        $code = strtoupper($applicationCode);

        return (string) (config('monitoring.sites.'.$code.'.display_name') ?? $this->site($code)?->display_name ?? $code);
    }

    /** 表示名＋コード（例: 蔵 WMS）。通知本文・一覧で使う。 */
    public function label(string $applicationCode): string
    {
        $code = strtoupper($applicationCode);

        return $this->displayName($code).' '.$code;
    }

    public function labelById(int $siteId): string
    {
        $site = $this->siteById($siteId);

        return $site ? $this->label($site->application_code) : '?';
    }

    public function siteById(int $id): ?Site
    {
        foreach ($this->map() as $s) {
            if ((int) $s->id === $id) {
                return $s;
            }
        }

        return null;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    /** 監視システム自身（チェック実行器・自己監視）が使う内部収集器。 */
    public function internalCollector(string $purpose = 'checker'): Collector
    {
        $site = $this->site('INSIGHTS') ?? Site::query()->firstOrCreate(
            ['application_code' => 'INSIGHTS'],
            ['display_name' => 'Insights', 'enabled' => true, 'coverage' => Coverage::CONFIGURED, 'sort_order' => 90],
        );
        $node = $purpose.'@'.$this->nodeCode();
        $existing = Collector::query()->where('site_id', $site->id)->where('node_code', $node)->first();
        if ($existing) {
            return $existing;
        }

        return Collector::query()->create([
            'collector_uuid' => \App\Monitoring\Support\Uuid::v5(\App\Monitoring\Support\Uuid::v5(\App\Monitoring\Support\Uuid::NS_MONITOR, 'sakemaru-monitor'), $this->deploymentId().'|'.$node),
            'site_id' => $site->id,
            'node_code' => $node,
            'status' => 'ACTIVE',
        ]);
    }

    public function findCollectorByUuid(string $uuid): ?Collector
    {
        return Collector::query()->where('collector_uuid', $uuid)->first();
    }

    /** @return array<string,mixed>|null 設定上の収集器定義 */
    public function collectorConfig(string $uuid): ?array
    {
        foreach ((array) config('monitoring.collectors', []) as $c) {
            if (($c['collector_uuid'] ?? null) === $uuid) {
                return $c;
            }
        }

        return null;
    }
}
