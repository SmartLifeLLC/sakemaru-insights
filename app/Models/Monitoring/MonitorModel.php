<?php

namespace App\Models\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 監視モデルの基底。接続は必ず monitor_store を明示し、業務モデルの save を呼ばない。
 * テーブル名は monitor_* とし、接続プレフィックス ins_ で実テーブル ins_monitor_* に対応する（二重付与禁止）。
 * 時刻は常に UTC で保存・復元する（アプリの timezone 設定に依存しない）。
 */
abstract class MonitorModel extends Model
{
    protected $connection = 'monitor_store';

    protected $guarded = [];

    public $timestamps = false;

    protected function asDateTime($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC');
        }
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value, 'UTC');
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value, 'UTC');
        }

        return parent::asDateTime($value);
    }

    public function fromDateTime($value)
    {
        if ($value === null) {
            return null;
        }

        return $this->asDateTime($value)->format($this->getDateFormat());
    }

    public function freshTimestamp()
    {
        return Carbon::now('UTC');
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
