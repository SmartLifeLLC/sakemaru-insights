<?php

namespace App\Models\Monitoring;

/** 脆弱性情報（外部情報源の公開アドバイザリ 1 件）。秘密値は持たない。 */
class Advisory extends MonitorModel
{
    public const STATUSES = ['NEW' => '新規', 'ACKNOWLEDGED' => '確認済み', 'NOT_APPLICABLE' => '対象外', 'RESOLVED' => '対応済み'];

    public const SEVERITIES = ['CRITICAL' => '重大', 'HIGH' => '高', 'MEDIUM' => '中', 'LOW' => '低', 'UNKNOWN' => '未評価'];

    public const SOURCES = ['nvd' => 'NVD', 'github' => 'GitHub Advisory', 'ubuntu' => 'Ubuntu USN', 'kev' => 'CISA KEV', 'aws' => 'AWS Security', 'jvn' => 'JVN iPedia'];

    protected $table = 'monitor_advisories';

    protected $casts = [
        'cvss' => 'float',
        'epss' => 'float',
        'kev' => 'bool',
        'installed_affected' => 'bool',
        'components_json' => 'array',
        'affected_json' => 'array',
        'published_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'status_at' => 'datetime',
    ];

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** 対応を促す対象（重大・悪用確認・導入版が影響）か */
    public function isUrgent(): bool
    {
        $cfg = (array) config('monitoring.advisories.alert_on');

        return (($cfg['critical'] ?? true) && $this->severity === 'CRITICAL')
            || (($cfg['kev'] ?? true) && $this->kev)
            || (($cfg['installed_affected'] ?? true) && $this->installed_affected);
    }
}
