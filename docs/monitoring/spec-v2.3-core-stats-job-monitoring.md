# 算盤 監視 v2.3 — 勘定衆（Core）の定時処理・売上統計の実行監視

> 状態: 設計確定（2026-11-04）。対象: 華様本番。実装順 ①→②→③。
> 目的: Core の cron 実行スキップ、開始後の停止、対象日の一部未集計、部門同期の未完了、前日確定の未完了、統計金額の不一致を、Insights の既存の収集・案件・通知経路で検知する。

## 1. 現状と不足

| 現状 | 不足 |
| --- | --- |
| `stats_import_runs` に対象日ごとの子処理（`daily_aggregate` / `daily_department_sync` / `daily_finalize`）が成功・失敗で残る | 予定枠（07:00 など）の**親処理**が「起動したか・どのノードか・どの対象日を決めたか・対象 0 件だったか」は残らない。`withoutOverlapping` で見送られた枠も区別できない |
| Insights の `core.stats_daily_aggregate` は「時間帯内に成功記録があれば正常」 | 対象日の一部欠落・別処理の成功による見逃し・26 時間を外れた追跡打切り |
| 各ノードのスケジューラ（`schedule:run`）の生存は未監視 | cron 停止＝全処理が静かに止まる |

## 2. Core 側の追加（最小限・業務データは変更しない）

### 2.1 `core_scheduler_heartbeats` — スケジューラ生存
| 列 | 内容 |
| --- | --- |
| node (PK, varchar 64) | `gethostname()` |
| last_tick_at (datetime) | `schedule:run` が呼んだ時刻 |
| app_version (varchar 64, null) | 参考 |

`CommonSchedule` に `->call(fn () => SchedulerHeartbeat::tick())->everyMinute()->name('scheduler-heartbeat')` を**ノードごと**（`onOneServer` 無し）に登録。UPSERT 1 回/分/ノード。

### 2.2 `core_scheduled_job_runs` — 予定枠ごとの親処理の記録
| 列 | 内容 |
| --- | --- |
| id | PK |
| client_id | クライアント |
| job_name (varchar 96) | `stats.refresh_updated` / `stats.finalize_daily` / `stats.sync_departments` / `stats.refresh_monthly` |
| slot_key (varchar 32) | 予定枠 `YYYY-MM-DD HH:MM`（JST。開始時刻を直近の予定へ丸める） |
| run_uuid (char 36) | 実行 ID |
| node (varchar 64) | 実行ノード |
| status (varchar 16) | `running` / `succeeded` / `failed` / `skipped` |
| skip_reason (varchar 32, null) | `no_targets` / `lock_busy` / `other_node` / `precondition_failed` |
| started_at / finished_at | 開始・終了 |
| exit_code (smallint, null) | 終了コード |
| target_dates_json (json) | **開始時に確定した対象日一覧**（空配列可） |
| target_count (int) | 対象件数 |
| result_json (json, null) | 対象日ごとの `{date, import_run_id, dept_sync_run_id, status}`、失敗日一覧 |
| error_message (text, null) | 失敗理由 |

索引: `(client_id, job_name, slot_key)` UNIQUE 相当（同じ枠の再実行は別 run_uuid で複数可 → `(client_id, job_name, slot_key, id)`）、`(status, started_at)`。

### 2.3 計装（`App\Services\Stats\ScheduledJobRun`）
```
$run = ScheduledJobRun::start('stats.refresh_updated', slotFrom(now()), $targetDates);   // 対象 0 件なら status=skipped/no_targets で即終了
... 対象日ごとに子処理 → $run->recordDate($date, $importRunId, $deptSyncRunId, $status)
$run->finish(succeeded|failed, $exitCode, $errorMessage)
```
対象の 4 コマンド: `stats:refresh-all-updated-daily-buyer-trade-profit`（07:00/08:10/09:00/12:00/16:00/19:00・02:00 の確定前段）、`stats:finalize-daily-buyer-trade-profit`（02:00）、`stats:sync-daily-department-trade-profit`（02:20）、`stats:refresh-all-updated-monthly-buyer-trade-profit`（15:00）。
`withoutOverlapping` で見送られた場合は Laravel がコマンドを呼ばないため記録されない → Insights は「開始記録なし」として検知（前の枠が `running` のままなら「長時間実行」の方で先に検知される）。
取消・計上日変更で旧日付が対象から漏れる問題は、対象日の決定ロジック（`DailyBuyerRefreshDates`）に `updated_at` 以外の根拠（`earning_date` 変更履歴）を追加する別課題として扱い、本仕様では「抽出漏れ疑い」を③の金額整合で検知する。

## 3. Insights 側のチェック（CORE）

すべて業務 DB へは索引付き SELECT のみ（1 秒上限・5 分予算 5 秒）。インシデントの指紋は「ジョブ＋予定枠」または「ジョブ＋対象日」で固定し、**同じ対象の完了でのみ復旧**する。未解決案件の対象は `ActiveScopes` 経由で判定範囲（直近 26 時間）を外れても再確認を続ける。

| チェック | 判定 | 既定の猶予（監視ルールで変更可） |
| --- | --- | --- |
| `core.scheduler_heartbeat` | ノードの `last_tick_at` が `stale_seconds` を超える → ERROR（ノード単位の指紋）。全ノード停止は CRITICAL | 180 秒 |
| `core.stats_job_slot` | 予定枠ごとに: 開始記録なし（予定＋`start_grace`）→ `STATS_JOB_NOT_STARTED` / `running` が完了期限超過 → `STATS_JOB_OVERRUN` / `failed` → `STATS_JOB_FAILED` / `skipped` は `no_targets`・`other_node` を正常ログ、`lock_busy` が 2 枠連続で WARNING | 開始 5 分、完了: 再集計 30 分、02:00 確定・02:20 同期は 02:45 まで、15:00 月次 60 分 |
| `core.stats_job_dates` | 親が `succeeded` でも `target_dates_json` の各日に、親開始以降の `daily_aggregate` 成功が無い → `STATS_DATE_INCOMPLETE`（対象日単位の指紋）。`sync_departments` 指定の枠で `department_sync_status != done` → `STATS_DEPT_SYNC_INCOMPLETE` | 親完了＋10 分 |
| `core.stats_daily_finalize`（置換） | 前日分: `daily_finalize` 成功（period に前日を含む）＋ `stats_daily_buyer_trade_profits.finalized_at` が前日分で NULL でない＋部門日次（`stats_daily_department_trade_profits`）が前日分で存在。別日の成功で代用しない | 02:45 |
| `core.stats_consistency`（③） | 対象日・部門ごとに、顧客別集計の売上／原価／粗利の合計と部門統計の値を**同じ `content_generation_id`** で比較。欠落・余分・金額差 → `STATS_AMOUNT_MISMATCH`。更新途中（親 `running`）は判定しない | 1 円以上の差 |
| `core.stats_monthly`（③） | 15:00 の月次再集計が当日成功していない → `STATS_MONTHLY_INCOMPLETE` | 16:00 |

既存の `core.stats_daily_aggregate` は `core.stats_job_slot` + `core.stats_job_dates` に置き換え（キー互換のため旧キーは「非推奨・無効」に）。

## 4. 画面・運用
- 監視ルール: `core.stats_job_slot` の `slots`（ジョブごとの予定時刻）と猶予を編集可。既定値は Core の `HanaSchedule` と一致させる。
- 対処手順 RB-21（スケジューラ停止）・RB-22（統計ジョブ未完了／部分未集計）・RB-23（金額不一致）を追加。
- 正常な「対象 0 件」「他ノード担当」「内容変更なし」は正常ログ（INFO）として監視ログに残す。
- 正常ログは 30 日で削除（既存の保持設定）。

## 5. 実装順
1. ① Core: 2 テーブル＋計装（ブランチ `feature/scheduled-job-runs`、Insights とは独立にリリース可。記録が無い間 Insights は「確認不可（UNKNOWN）」）。Insights: `core.scheduler_heartbeat`・`core.stats_job_slot`。
2. ② Insights: `core.stats_job_dates`・`core.stats_daily_finalize` 置換・継続追跡。
3. ③ Insights: `core.stats_consistency`・`core.stats_monthly`。
