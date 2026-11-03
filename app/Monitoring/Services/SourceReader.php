<?php

namespace App\Monitoring\Services;

use App\Monitoring\Exceptions\SourceBudgetExceeded;
use App\Monitoring\Exceptions\SourceUnavailable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * 業務DBへの限定 SELECT（仕様書 4.1 / 7.1）。
 * - monitor_source 接続のみ。未設定時は default 接続へフォールバックせず SourceUnavailable。
 * - SELECT 以外・FOR UPDATE・LOCK 等を拒否。1文ごとの実行上限（MAX_EXECUTION_TIME）と総時間予算を適用。
 * - 生SQL・bind 値は保存せず、件数と所要時間だけを証跡に残す。
 */
class SourceReader
{
    private int $queryCount = 0;

    private int $queryMs = 0;

    private int $maxQueryMs = 0;

    private ?Connection $conn = null;

    public function __construct(
        private readonly int $budgetMs,
        private readonly int $lightTimeoutMs,
        private readonly int $detailTimeoutMs,
        private readonly int $lockWaitSeconds = 1,
        private readonly ?string $connectionName = 'monitor_source',
    ) {}

    public static function fromConfig(?int $budgetMs = null): self
    {
        return new self(
            $budgetMs ?? (int) config('monitoring.budget.sql_budget_ms_per_5min', 5000),
            (int) config('monitoring.budget.light_sql_timeout_ms', 1000),
            (int) config('monitoring.budget.detail_sql_timeout_ms', 3000),
            (int) config('monitoring.budget.lock_wait_timeout_seconds', 1),
        );
    }

    public function isConfigured(): bool
    {
        $cfg = config('database.connections.'.$this->connectionName);

        return is_array($cfg) && ! empty($cfg['database']);
    }

    private function connection(): Connection
    {
        if ($this->conn) {
            return $this->conn;
        }
        if (! $this->isConfigured()) {
            throw new SourceUnavailable('monitor_source 接続が未設定です（MONITOR_SOURCE_DATABASE）。default 接続へはフォールバックしません。');
        }
        try {
            $conn = DB::connection($this->connectionName);
            if ($conn->getDriverName() === 'mysql') {
                // メタデータ待ち・行ロック待ちを短く打ち切る。読取り専用トランザクションは保持しない（autocommit）。
                $conn->statement('SET SESSION lock_wait_timeout = '.(int) $this->lockWaitSeconds);
                $conn->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $this->lockWaitSeconds);
                $conn->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
        } catch (\Throwable $e) {
            // 接続不能・認証失敗は「業務DBに届かない」として扱う（予算超過や例外と区別。接続情報は含めない）
            throw new SourceUnavailable('monitor_source に接続できません: '.class_basename($e), 0, $e);
        }

        return $this->conn = $conn;
    }

    /**
     * 軽量 SELECT（上限 1 秒）。
     *
     * @return array<int,object>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, $this->lightTimeoutMs);
    }

    /**
     * 明細照合 SELECT（上限 3 秒）。
     *
     * @return array<int,object>
     */
    public function selectDetail(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, $this->detailTimeoutMs);
    }

    public function scalar(string $sql, array $bindings = [], mixed $default = null): mixed
    {
        $rows = $this->select($sql, $bindings);
        if (! $rows) {
            return $default;
        }
        $first = (array) $rows[0];

        return reset($first);
    }

    private function run(string $sql, array $bindings, int $timeoutMs): array
    {
        $this->assertSafe($sql);
        if ($this->queryMs >= $this->budgetMs) {
            throw new SourceBudgetExceeded("SQL 時間予算 {$this->budgetMs}ms を使い切りました（使用 {$this->queryMs}ms）");
        }
        $conn = $this->connection();
        if ($conn->getDriverName() === 'mysql') {
            $sql = preg_replace('/^\s*SELECT\s+/i', 'SELECT /*+ MAX_EXECUTION_TIME('.(int) $timeoutMs.') */ ', ltrim($sql), 1);
        }
        $t = hrtime(true);
        try {
            $rows = $conn->select($sql, $bindings);
        } finally {
            $ms = (int) ((hrtime(true) - $t) / 1_000_000);
            $this->queryCount++;
            $this->queryMs += $ms;
            $this->maxQueryMs = max($this->maxQueryMs, $ms);
        }

        return $rows;
    }

    private function assertSafe(string $sql): void
    {
        $s = strtolower(trim($sql));
        if (! preg_match('/^(select|with)\b/', $s)) {
            throw new \LogicException('SourceReader は SELECT のみ許可します。');
        }
        if (preg_match('/\b(for\s+update|for\s+share|lock\s+in\s+share\s+mode|lock\s+tables|into\s+outfile|into\s+dumpfile|insert|update|delete|replace|alter|drop|truncate|create|grant|call)\b|\bsleep\s*\(/', $s)) {
            throw new \LogicException('SourceReader で禁止された SQL です。');
        }
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function queryMs(): int
    {
        return $this->queryMs;
    }

    public function maxQueryMs(): int
    {
        return $this->maxQueryMs;
    }

    public function remainingBudgetMs(): int
    {
        return max(0, $this->budgetMs - $this->queryMs);
    }

    public function driver(): string
    {
        return $this->isConfigured() ? $this->connection()->getDriverName() : 'none';
    }
}
