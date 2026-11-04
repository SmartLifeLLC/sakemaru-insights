<?php

namespace App\Monitoring\Advisories;

use App\Models\Monitoring\Advisory;
use App\Models\Monitoring\AdvisoryFetch;
use App\Monitoring\Services\AuditService;
use Carbon\CarbonImmutable;

/** 脆弱性情報の状態変更と、画面・ダッシュボード・監視項目で共有する集計。 */
class AdvisoryService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array{id:int|null,name:string} $actor */
    public function setStatus(Advisory $a, string $status, array $actor, ?string $note): Advisory
    {
        if (! isset(Advisory::STATUSES[$status])) {
            throw new \InvalidArgumentException('状態が不正です。');
        }
        if (in_array($status, ['NOT_APPLICABLE', 'RESOLVED'], true) && trim((string) $note) === '') {
            throw new \InvalidArgumentException('理由を入力してください。');
        }
        $before = ['status' => $a->status, 'note' => $a->status_note];
        $a->forceFill(['status' => $status, 'status_user_id' => $actor['id'] ?? null, 'status_name' => $actor['name'] ?? null, 'status_note' => $note !== null ? mb_substr($note, 0, 1000) : null, 'status_at' => CarbonImmutable::now('UTC')])->save();
        $this->audit->record($actor, 'advisory.status', 'advisory', (string) $a->id, $before, ['status' => $status, 'note' => $note], $note);

        return $a;
    }

    /**
     * ダッシュボード・監視項目用の集計。
     *
     * @return array{new:int,urgent:int,critical:int,kev:int,installed:int,last_fetch_at:?CarbonImmutable,failed_sources:list<string>,stale:bool,never:bool,sources_ok:int,sources_total:int}
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $new = Advisory::query()->where('status', 'NEW');
        $critical = (clone $new)->where('severity', 'CRITICAL')->count();
        $kev = (clone $new)->where('kev', true)->count();
        $installed = (clone $new)->where('installed_affected', true)->count();
        $urgent = (clone $new)->where(fn ($q) => $q->where('severity', 'CRITICAL')->orWhere('kev', true)->orWhere('installed_affected', true))->count();
        $enabled = (array) config('monitoring.advisories.sources');
        $latest = AdvisoryFetch::query()->whereIn('source', $enabled)->orderByDesc('id')->limit(200)->get()->groupBy('source')->map(fn ($g) => $g->first());
        $failed = $latest->filter(fn ($f) => $f->status === 'FAILED')->keys()->values()->all();
        $lastOk = AdvisoryFetch::query()->where('status', 'OK')->max('started_at');
        $lastOkAt = $lastOk ? CarbonImmutable::instance(AdvisoryFetch::query()->where('status', 'OK')->orderByDesc('id')->first()->started_at) : null;
        $staleHours = (int) config('monitoring.advisories.stale_hours', 30);

        return [
            'new' => (clone $new)->count(), 'urgent' => $urgent, 'critical' => $critical, 'kev' => $kev, 'installed' => $installed,
            'last_fetch_at' => $lastOkAt, 'failed_sources' => $failed,
            'stale' => $lastOkAt === null || $lastOkAt->lt($now->subHours($staleHours)), 'never' => $lastOkAt === null,
            'sources_ok' => $latest->filter(fn ($f) => $f->status === 'OK')->count(), 'sources_total' => count($enabled),
        ];
    }

    /** ダッシュボード 1 行の文言とレベル（ok / warn / error / muted） */
    public function headline(?CarbonImmutable $now = null): array
    {
        $s = $this->summary($now);
        $jst = fn (?CarbonImmutable $t) => $t ? $t->setTimezone('Asia/Tokyo')->format('m/d H:i') : '—';
        if (! config('monitoring.advisories.enabled')) {
            return ['level' => 'muted', 'text' => '脆弱性情報の収集は無効です。', 'summary' => $s];
        }
        if ($s['never']) {
            return ['level' => 'muted', 'text' => '脆弱性情報はまだ取得されていません（monitor:fetch-advisories）。', 'summary' => $s];
        }
        if ($s['urgent'] > 0) {
            $parts = array_filter([$s['critical'] ? '重大 '.$s['critical'] : null, $s['kev'] ? '悪用確認 '.$s['kev'] : null, $s['installed'] ? '導入版が影響 '.$s['installed'] : null]);

            return ['level' => 'error', 'text' => '脆弱性報告が '.$s['new'].' 件あります（'.implode('・', $parts).'）。確認してください。', 'summary' => $s];
        }
        if ($s['new'] > 0) {
            return ['level' => 'warn', 'text' => '新しい脆弱性情報が '.$s['new'].' 件あります（重大・悪用確認なし）。最終取得 '.$jst($s['last_fetch_at']).'。', 'summary' => $s];
        }
        $tail = $s['failed_sources'] !== [] ? '（取得失敗: '.implode(', ', array_map(fn ($k) => Advisory::SOURCES[$k] ?? $k, $s['failed_sources'])).'）' : '';

        return ['level' => $s['stale'] || $s['failed_sources'] !== [] ? 'warn' : 'ok', 'text' => '現在、新規の脆弱性情報はありません。最終取得 '.$jst($s['last_fetch_at']).'（'.$s['sources_ok'].' / '.$s['sources_total'].' 情報源）'.$tail, 'summary' => $s];
    }
}
