# 運用監視 v2 開発仕様書 — 監視対象カタログ・顧客別 ON/OFF 管理・サーバー資源監視・画面刷新

版: 2.3（実装反映: docs/monitoring/v2-implementation-status.md） / 作成日: 2026-11-03 JST（21:30 更新: SMTP/Twilio を管理画面で設定、ノードは .env 自己登録、本番実績からのスケジュール仮置き） / 対象リポジトリ: sakemaru-insights / 前提: v1.1 仕様書と初回実装（2026-11-03）

本書は v1（Trade 中心の監視・エラー管理）を、全サービスへ拡張するための仕様である。実装は本書の「第9章 実装手順」「第10章 検証項目」を単位に進め、各項目の完了を記録する。v1 の原則（1 DB = 1 client、業務DBは SELECT のみ、短い取引、キーセットページング、未解決エラーを削除しない、Slack 送信済み ≠ 確認済み）はすべて継続する。

---

## 1. 目的・範囲

| 項目 | 内容 |
| --- | --- |
| 目的 1 | 監視対象を全サービス（勘定衆・乃蓮・蔵・飛脚・千里眼・算盤）に広げ、**顧客が使う機能だけを監視**できるようにする |
| 目的 2 | 監視対象の **ON/OFF を画面で管理**し、変更を監査に残す（顧客ごとに使う機能が異なる） |
| 目的 3 | 各サーバーの **CPU・メモリ・ディスク・負荷**を監視する（平常時 30 秒、警告域で 1 秒バースト、ローカル 60 日、DB は 5 分集約） |
| 目的 4 | 画面を近年の監視システムの構成（サイドバー、ダッシュボード、期間比較、サービス稼働バー、処理フロー、ダーク/ライト）に刷新する |
| 対象外 | 業務データの修正、他アプリのスケジュール変更、AWS 連携の必須化、Netdata 等の外部監視デーモン導入、全文検索基盤、別顧客の横断表示 |

## 2. 名称・用語（システム内の表示名を日本語で統一）

| application_code | 表示名（日本語） | 英語/リポジトリ | 役割 | 業務テーブル接頭辞 |
| --- | --- | --- | --- | --- |
| `CORE` | **勘定衆** | sakemaru-ai-core（旧「酒丸（基幹）」） | 販売管理・統計 | （接頭辞なし）, `stats_`, `closing_`, `log_` |
| `TRADE` | **乃蓮** | sakemaru-trade | 小売取引・POS 連携 | `ret_` |
| `WMS` | **蔵** | sakemaru-wms | 倉庫・自動発注・EOS/JX | `wms_` |
| `DELIVERY` | **飛脚** | sakemaru-delivery | 配送・集金・容器回収 | `dlv_` |
| `SEARCH` | **千里眼** | sakemaru-search | 商品検索 | `srh_` |
| `INSIGHTS` | **算盤** | sakemaru-insights | 本監視・分析 | `ins_` |

- 画面・通知・対処手順では日本語名を主、コード（CORE 等）を副として表示する（例: 「蔵 WMS」）。
- 用語: 「サービス」= application_code 単位、「機能」= サービス内で顧客が使う/使わない単位（自動発注、JX 連携 など）、「監視項目」= check_key 単位、「ノード」= サーバー。
- `config/monitoring.php` の `sites.*.display_name` を上記に変更する。MegaMenu の表示名（酒丸乃蓮 等）はそのまま。

## 3. 監視対象カタログ

凡例 — 状態: 実装済 / 新規 / 未実装（根拠待ち）。根拠: 2026-11-03 に本番 DB（prd_hana、読取り専用）で確認した実テーブル・実データ。判定開始・猶予は初期値で、画面（監視ルール）から変更可。

### 3.1 乃蓮（TRADE）— v1 実装済

| check_key | 内容 | 根拠テーブル | 判定 | 状態 |
| --- | --- | --- | --- | --- |
| trade.coupon_daily | クーポン日別統計 vs 元POS | ret_app_coupon_usage_daily_statistics, ret_tm_received_raw_sale_items, ret_coupon_campaigns, ret_stores | 翌 00:50 以降、不一致 ERROR | 実装済（主指標 transaction_count） |
| trade.member_daily | 会員日別統計行の存在 | ret_app_member_daily_statistics, ret_pos_member_purchases | 翌 00:45 以降、欠落 ERROR | 実装済 |
| trade.hourly_receive | 時刻指定受信の完了 | ret_tm_receive_requests | 締切後、他経路成功ありは WARNING | 実装済 |
| trade.transfer_pending | 送受信の滞留 | ret_tm_store_sync_requests, ret_tm_receive_requests | 15分/2h 超 ERROR | 実装済 |
| trade.daily_transfer | 日次締め受信 | ret_tm_receive_requests(daily_close) | 00:10＋2h | 実装済 |
| trade.mail / intage / fixed_point / promo / daily_report | 外部送信・帳票 | 各 schedules / runs | 予定＋猶予 | 実装済 |
| trade.item_last_use | 最終使用日 | 未確認 | — | 未実装 |

### 3.2 蔵（WMS）— 新規

| check_key | 内容 | 根拠テーブル（確認済み） | 判定 | 重大度 | RB |
| --- | --- | --- | --- | --- | --- |
| **wms.auto_order_run** | 自動発注の定期実行（slot 01:00 等）が完了しているか | `wms_auto_order_runs`（slot_key = `YYYY-MM-DD:HH:MM`、status COMPLETED、business_date、generated/held/failed_count、is_preview=0）、`wms_warehouse_auto_order_settings.is_auto_order_enabled`（倉庫別の有効設定） | 有効倉庫があるのに当日 slot の COMPLETED が slot 時刻＋90 分までに無い → ERROR。failed_count>0 → WARNING（内容付き）。preview は除外。本番実績: slot `01:00`（11 倉庫、11/03 が初回。曜日の運用は未確定のため正式スケジュール確認後に `config_json.slots` を確定） | ERROR | RB-11 |
| **wms.auto_order_calc** | 発注計算ジョブ（ORDER_CALC / SALES_BASED_CALC）の失敗・滞留 | `wms_auto_order_job_controls`（process_name, status PENDING/RUNNING/SUCCESS/FAILED, started_at, finished_at）, `wms_auto_order_execution_log`（status, transmission_status, error_details） | FAILED（直近 24h）→ ERROR、RUNNING 60 分超 → ERROR、PENDING 30 分超 → WARNING | ERROR | RB-11 |
| **wms.jx_order_generation** | JX 発注データ生成（13:30 / 23:35 等）の完了 | `wms_jx_order_generation_runs`（generation_time, cutoff_time, target_date, status SUCCESS, generated_document_count, error_message） | 本番の実績（10/04〜11/03）: **月〜土 13:30、日曜 23:35**（土曜は 13:35 開始の日もあり）。予定を `config_json.slots`（曜日別）に持ち、予定＋30 分までに SUCCESS が無い → ERROR。FAILED → ERROR。予定は正式スケジュール確認後に確定 | ERROR | RB-12 |
| **wms.jx_transmission** | JX 送受信（PutDocument/GetDocument）の失敗 | `wms_jx_transmission_logs`（direction send/receive, operation_type, document_type, status, http_code, error_message, transmitted_at） | 直近 24h の status≠success → ERROR（指紋: direction+operation+document_type、日付なし）。送信ゼロが 48h 継続かつ JX 設定が有効 → WARNING | ERROR | RB-12 |
| **wms.order_transmission** | 発注データ送信（JX_FINET/FTP）の失敗・未確認 | `wms_order_transmission_logs`（transmission_type, action, status SUCCESS/FAILED/PENDING, error_code）, `wms_order_jx_documents`（status, transmitted_at, confirmed_at） | FAILED → ERROR。PENDING 60 分超 → WARNING。transmitted_at から 24h 経過で confirmed_at 無し → WARNING（**本番で confirmed_at 未使用のため既定 OFF**。`unconfirmed_warning` で有効化） | ERROR | RB-12 |
| **wms.eos_incoming_receive** | EOS 入荷予定受信（スケジュール実行）の完了 | `wms_eos_incoming_receive_schedules`（is_enabled, receive_time, day_of_week）, `wms_eos_incoming_receive_runs`（execution_date, scheduled_time, status SUCCEEDED, error_count, error_summary） | スケジュール表そのものを予定にする（本番: DAILY 22:00、WEEKLY_EXTRA 月曜 10:00 / 16:00 が有効、他 12 件は無効）。有効スケジュールの receive_time＋30 分までに SUCCEEDED が無い → ERROR。error_count>0 → WARNING | ERROR | RB-13 |
| wms.queue_failed | キュー失敗ジョブ | `wms_failed_jobs`（failed_at） | 直近 24h の新規 → ERROR（指紋: exception 正規化） | ERROR | RB-07 |
| wms.incoming_import_errors | 入荷データ取込エラー | `wms_incoming_import_errors` | 直近 24h の件数しきい値超過 → WARNING | WARNING | RB-13 |

### 3.3 勘定衆（CORE）— 新規

| check_key | 内容 | 根拠テーブル（確認済み） | 判定 | 重大度 | RB |
| --- | --- | --- | --- | --- | --- |
| **core.stats_daily_aggregate** | 日次統計集計（daily_aggregate / daily_department_sync）の定時実行 | `stats_import_runs`（job_type, source_system, status succeeded, period_from/to, started_at, finished_at, error_message） | 本番の実績（10/27〜11/03）: 02:00（日次バッチ、多数日分）, 07:00, 08:10, 09:00, 12:00, 16:00, 19:00 の 7 枠。各枠＋30 分までに succeeded（daily_aggregate と daily_department_sync の両方）が無い → ERROR。status=failed → ERROR。枠は `config_json.slots` に持ち、正式スケジュール確認後に確定する | ERROR | RB-14 |
| **core.stats_daily_finalize** | 日次確定（daily_finalize）と部門同期（daily_department_sync/mixed 02:20）の完了 | 同上（job_type=daily_finalize, period_to=前営業日） | 実績: 02:00–02:15 に finalize、02:20 に mixed 同期。**02:45** までに前営業日の succeeded が無い → ERROR | ERROR | RB-14 |
| core.stats_sales_performance | 販売実績集計ランの失敗・滞留 | `stats_sales_performance_runs`（status, progress_*, started_at, completed_at, error_message） | failed → ERROR、running で progress_updated_at が 30 分更新なし → WARNING | ERROR | RB-14 |
| core.closing_process | 締め処理（supplier_bill / buyer_bill）の失敗 | `closing_processes`（process_type, status completed/failed, closing_date, error_count, error_message） | 直近 24h の failed → ERROR（指紋: process_type + closing_date） | ERROR | RB-15 |
| **core.external_collaboration** | 外部連携取込（EARNING / DEPOSIT / PURCHASE / PAYMENT 等、全パートナー）の失敗 | `external_collaboration_data`（edi_type, external_collaboration_partner, data_method, status, total_lines, success_count, failed_count, created_at） | 直近 24h の VALIDATION_ERROR / APPLY_ERROR → ERROR（指紋: edi_type + partner、日付なし）。failed_count>0 → WARNING（例: SUMATYU の EARNING で failed 67）。APPLY_WAIT が 2h 超 → WARNING | ERROR | RB-18 |
| core.queue_failed | キュー失敗ジョブ | `failed_jobs` | 同 wms.queue_failed | ERROR | RB-07 |

### 3.4 千里眼（SEARCH）— 新規

| check_key | 内容 | 根拠テーブル（確認済み） | 判定 | 重大度 | RB |
| --- | --- | --- | --- | --- | --- |
| **search.sync_items_full** | 検索データの全件更新（毎日 03:00 頃） | `srh_search_sync_states`（name=searchable_items_full, status, last_started_at, last_success_at, last_finished_at, last_error） | last_success_at が 当日 04:00 までに更新されない → ERROR。status≠success → ERROR（last_error を証拠に） | ERROR | RB-16 |
| **search.sync_item_stocks** | 在庫差分更新（15 分おき） | 同上（name=searchable_item_stocks） | last_success_at が 45 分以上前 → WARNING、90 分以上 → ERROR | WARNING→ERROR | RB-16 |
| search.queue_failed | キュー失敗ジョブ | `srh_failed_jobs` | 同上 | ERROR | RB-07 |

### 3.5 飛脚（DELIVERY）— 新規

| check_key | 内容 | 根拠テーブル（確認済み） | 判定 | 重大度 | RB |
| --- | --- | --- | --- | --- | --- |
| **delivery.operation_sync** | 配送端末の送信（集金・容器回収データを含む同期）の滞留・失敗 | `dlv_operations`（status STARTED/RETURN_TO_WAREHOUSE/PRINTED_COLLECTION/READY_TO_SYNC/WAIT_SYNC/IN_PROGRESS_SYNC/FAILED_TO_SYNC/FINISHED, transfer_begin, finished_at, user_id, app_version） | FAILED_TO_SYNC → ERROR（指紋: operation id）。READY_TO_SYNC / WAIT_SYNC が 60 分超 → WARNING、IN_PROGRESS_SYNC 30 分超 → ERROR。前日分で FINISHED でないものが翌 06:00 に残る → ERROR | ERROR | RB-17 |
| **delivery.collected_money_check** | 集金データの管理者確認待ち・不正検知 | `dlv_item_delivery_collected_monies`（cash_fraud_detection_status, need_admin_check, admin_check_date, is_cash） | status が RECEIPT_PRINTED_MULTIPLE_TIME / AMOUNT_NOT_MATCHED_RECEIPT / RECEIPT_NOT_PRINTED → WARNING（件数集約、日付あり）。need_admin_check=1 かつ 48h 未確認 → WARNING | WARNING | RB-17 |
| **delivery.external_sync** | 飛脚 → 勘定衆 への集金（DEPOSIT）・容器回収（EMPTY_CONTAINER_PICKUP）データの連携完了 | 勘定衆側 `external_collaboration_data`（external_collaboration_partner = `MOBILE_COLLECT`, data_method = API, edi_type DEPOSIT / EMPTY_CONTAINER_PICKUP, status, total_lines, success_count, failed_count, created_at）と `external_collaboration_deposits` / `external_collaboration_container_pickups`（status APPLY_WAIT/APPLY_ERROR/APPLYING/APPLYING_SUCCESS, error_* フラグ） | 飛脚側 `dlv_operations` が FINISHED になった運行に対し、同日 02:30 までに勘定衆側へ DEPOSIT と EMPTY_CONTAINER_PICKUP の APPLYING_SUCCESS が存在する（運行件数 >0 で連携 0 件 → ERROR）。VALIDATION_ERROR / APPLY_ERROR → ERROR。failed_count>0 または明細の error_* フラグ → WARNING（件数・代表例を証拠） | ERROR | RB-17 |
| delivery.container_collect | 容器回収データの到着（配送実績があるのに回収データが無い） | `dlv_empty_container_collects`, `dlv_item_deliveries` | 前営業日の配送件数 >0 かつ回収データ 0 件 → WARNING | WARNING | RB-17 |
| delivery.queue_failed | キュー失敗ジョブ | `dlv_failed_jobs` | 同上 | ERROR | RB-07 |

※ 「送信データの監視」は、端末→飛脚サーバー同期（`dlv_operations`）と、飛脚→勘定衆の連携（`external_collaboration_data`、partner `MOBILE_COLLECT`、API 経由）の 2 段で監視する。本番確認（10/20〜11/03）: DEPOSIT 189 件・EMPTY_CONTAINER_PICKUP 2,107 件がすべて APPLYING_SUCCESS、最終 11/03 02:03（`dlv_operations` の FINISHED 最終時刻と一致）。飛脚側の送信コード（`sakemaru-delivery` の item-deliveries 画面配下の送信処理）は実装時に読み、送信失敗時の痕跡（リトライ・ログ）が飛脚側にもあればチェックに加える。

### 3.6 共通（全サービス）

| check_key / 種別 | 内容 | 状態 |
| --- | --- | --- |
| `site.application_error`（APPLICATION_LOG） | 各サービスの Laravel ログ ERROR 以上 | 実装済（収集器登録が必要） |
| `http.probe`（新規 / HTTP_PROBE） | 各サービスのヘルスチェック URL の応答コード・応答時間。Insights ノードから 60 秒間隔、タイムアウト 5 秒 | 新規。応答時間は「統計・比較」の応答時間グラフの元データ。**現状どのサービスにも `/up` は無い**（確認済み）ため、各サービスへ Laravel 標準のヘルスルートを追加する（下記） |

ヘルスチェック URL の追加（各サービスへの小さな差分、別コミット）:
- Laravel 11/12 標準の `bootstrap/app.php` → `->withRouting(..., health: '/up')`。DB・セッションに触れず、アプリ起動と `DiagnosingHealth` イベントだけで 200 を返す。負荷はログイン画面の 1/10 以下で、60 秒に 1 回なら無視できる。
- 認証不要・GET のみ・レスポンス本文は固定。Web サーバー側でアクセスログから除外してよい。
- 旧 Laravel（< 11）のサービスがあれば、`routes/web.php` に同等の軽量ルートを 1 本追加する。
- 追加前の暫定: `HEAD /admin/login`（200 を期待）をプローブ対象にできるが、セッション生成を伴うため暫定に限る。
| `node.cpu / node.memory / node.disk / node.load / node.io`（SERVER_METRICS） | 第5章 | 新規 |
| `monitor.*`（INSIGHTS 自己監視） | collector_stale / check_stale / notification / storage | 実装済 |
| `monitor.probe_stale`（新規） | HTTP プローブと資源収集の停止検知 | 新規 |

## 4. 顧客別の監視 ON/OFF 管理

### 4.1 考え方

- 顧客によって使う機能が異なるため、**サービス → 機能 → 監視項目** の 3 段で有効/無効を持つ。上位が無効なら下位は実行されず、画面には「未監視（機能未使用）」と表示する（HEALTHY と表示しない）。
- 機能の使用有無は業務設定から**推奨値を自動検出**して表示するが、自動では切り替えない（管理者が確認して保存。監査に残す）。

| 機能キー | サービス | 監視項目 | 推奨値の検出元 |
| --- | --- | --- | --- |
| trade.pos_stats | 乃蓮 | coupon_daily, member_daily, hourly_receive, daily_transfer, transfer_pending | ret_tm_receive_requests の直近 7 日の有無 |
| trade.external_send | 乃蓮 | mail, intage, fixed_point, promo, daily_report | 各 schedules の schedule_enabled |
| wms.auto_order | 蔵 | auto_order_run, auto_order_calc | wms_warehouse_auto_order_settings.is_auto_order_enabled の有無 |
| wms.jx | 蔵 | jx_order_generation, jx_transmission, order_transmission | wms_order_jx_settings の件数、直近 30 日の jx_transmission_logs |
| wms.eos_incoming | 蔵 | eos_incoming_receive, incoming_import_errors | wms_eos_incoming_receive_schedules.is_enabled |
| core.stats | 勘定衆 | stats_daily_aggregate, stats_daily_finalize, stats_sales_performance | stats_import_runs の直近 7 日 |
| core.closing | 勘定衆 | closing_process | closing_processes の直近 90 日 |
| search.sync | 千里眼 | sync_items_full, sync_item_stocks | srh_search_sync_states の有無 |
| delivery.sync | 飛脚 | operation_sync | dlv_operations の直近 30 日 |
| delivery.money | 飛脚 | collected_money_check | dlv_item_delivery_collected_monies の直近 30 日 |
| delivery.external | 飛脚 | external_sync, container_collect | external_collaboration_data（MOBILE_COLLECT）の直近 30 日 |
| core.external | 勘定衆 | external_collaboration | external_collaboration_data の直近 30 日（パートナー別） |
| *.app_log | 各 | site.application_error | 収集器の登録 |
| *.http | 各 | http.probe | URL 登録 |
| node.metrics | 各ノード | node.* | ノード登録 |

### 4.2 データモデル（追加・変更）

| テーブル | 主要列 | 備考 |
| --- | --- | --- |
| `ins_monitor_sites`（変更） | + `enabled_reason`, `updated_by`, `updated_at` | 表示名を日本語へ |
| **`ins_monitor_features`**（新規） | id, site_id, feature_key, display_name, enabled, recommended_enabled(bool/null), recommended_reason, recommended_checked_at, updated_user_id, updated_name, reason, updated_at | UNIQUE(site_id, feature_key)。コード定義（FeatureRegistry）から同期 |
| `ins_monitor_checks`（変更） | + `feature_key`, + `rule_json`（しきい値・比較条件・重大度・通知動作: notify/record_only）, + `priority` | 既存の enabled/grace/config_json は維持 |
| **`ins_monitor_nodes`**（新規） | id, node_code, display_name, role(web/batch/db/other), enabled, os_info_json, thresholds_json, last_heartbeat_at, last_metrics_at, registered_from(env), updated_user_id, reason, created_at, updated_at | UNIQUE(node_code)。**ノードの識別は各サーバーの `.env`**（`MONITOR_NODE_CODE`, `MONITOR_NODE_ROLE`, `MONITOR_NODE_DISPLAY_NAME`）で固定し、収集器の初回 heartbeat で自動登録する（画面からの手入力は不要）。画面で変更できるのは表示名・有効/無効・しきい値のみ。`.env` のノードコードを変えると別ノードとして登録されるため、変更時は旧ノードを無効化する |
| **`ins_monitor_targets`**（新規） | id, kind(APPLICATION_LOG/HTTP_PROBE/SERVER_METRICS/PROCESS), site_id, node_id, params_json（ログパス・URL・プロセス名）, thresholds_json, route_key, enabled, version, updated_user_id, reason, created_at, updated_at | 収集器のログパスを config から移行。パスは許可ディレクトリ配下の絶対パスのみ（サーバー側で検証、glob/URL 不可） |
| **`ins_monitor_server_metrics`**（新規） | id, node_id, bucket_start(5 分), cpu_avg/max, iowait_avg/max, load1_avg/max, mem_used_pct_avg/max, swap_used_pct_max, disk_json（マウント別 used_pct/free_gb）, disk_io_json, net_json, psi_json, sample_count, expires_at | UNIQUE(node_id, bucket_start)、(expires_at, id) |
| **`ins_monitor_probe_results`**（新規） | id, target_id, checked_at, http_status, duration_ms, ok, error_code, expires_at | 60 秒 1 行/URL、30 日保持。「応答時間」グラフ用の 1 時間集約は counters へ |
| `ins_monitor_counters`（変更） | counter_key を拡張: severity_hourly（既存）, outcome_hourly, notification_hourly(queued/delivered/failed/suppressed), probe_hourly(avg/max duration, fail) | ダッシュボード統計・処理フロー・ヒートマップの元データ。全件 COUNT をしない原則を維持 |
| `ins_monitor_audit_events`（既存） | 機能/ノード/対象/ルールの変更を記録 | 変更理由必須 |

### 4.3 画面（監視ルール = v1 の監視設定を再編）

- タブ: **サービスと機能**（サービス行 → 機能行 → 監視項目行のツリー。各行に ON/OFF、推奨値バッジ「推奨: 有効（直近 7 日に実行あり）」、最終完了・最終結果）／**ルール一覧**（しきい値・比較条件・重大度・間隔・一致時の動作 通知/記録のみ・優先順位・判定テスト）／**ノードと対象**（ノード台帳、ログパス・URL・プロセス登録）／**変更履歴**（監査）。
- 判定テスト: 入力値に対してしきい値判定の結果だけを返す（業務DBは叩かない）。
- 操作権限: 閲覧は monitor.view、ON/OFF・しきい値・対象の編集は **monitor.manage のみ**。変更理由必須。
- 顧客追加時の手順: サイト同期 → 推奨値の自動検出（`monitor:detect-features`）→ 管理者が画面で確定 → 収集器・プローブ登録。

### 4.4 推奨値の自動検出（`monitor:detect-features`）

- 1 日 1 回（owner ノード）、各機能の検出 SQL（軽量 SELECT、1 秒上限）を実行し `recommended_enabled` / `recommended_reason` を更新する。
- 現在の enabled と推奨が食い違う場合、監視ルール画面に「要確認」バッジを出す（通知はしない）。

## 5. サーバー資源監視（決定事項: 2026-11-03）

| 項目 | 決定 |
| --- | --- |
| 取得 | 既存収集器（`monitor:collect-logs` 常駐）が `/proc`・`statvfs` を直読み。外部ツールを毎回起動しない |
| 間隔 | 平常時 **30 秒**。警告域（CPU 70% / iowait 10% / メモリ available 15% / load > コア数×1.5）に入っている間＋前後 2 分は **1 秒バースト** |
| ローカル保存 | 日別固定長バイナリ（float32 列指向）、**60 日**。約 150KB/日/台。削除は unlink のみ |
| DB | `ins_monitor_server_metrics` に **5 分集約**（min/avg/max/last）、365 日 |
| 指標 | CPU（user/system/iowait/steal/idle）、load 1/5/15、PSI、メモリ、swap、マウント別ディスク使用率・空き・inode、デバイス別 IOPS/MB/s/util、IF 別 rx/tx、`ss -s`（60 秒に 1 回）の接続数、登録プロセスの生存 |
| しきい値 → エラー | ノード別 thresholds_json。継続時間で判定（例: CPU 90% が 5 分、ディスク 85% 超、available 5% 未満、iowait 30% が 5 分）。指紋 = node + metric（日付なし）。連続 N 回正常で RECOVERED |
| 画面 | ダッシュボードのノードカード、ノード詳細（24h/7 日/1 年は 5 分集約、範囲指定で 30 秒値・1 秒バースト） |
| 全停止検知 | 同一顧客 2 台以上: ノード同士の heartbeat。1 台: 代替通知先（外形監視）。AWS は必須にしない |
| 調査用 | 各サーバーで sysstat（10 秒、60 日）・atop（60 秒、30 日）を有効化。Insights とは連携しない。RB に手順を記載 |

## 6. 画面刷新（添付モックアップ「酒丸 Monitor」準拠）

### 6.1 構成

- **専用 Filament パネル `/monitor`**（既存 `/admin` の統計画面とは分離。同じ認証・セッション）。左サイドバー、上部バー、ダーク/ライト切替（ユーザー設定を localStorage、既定はダーク）。
- サイドバー: ワークスペース（顧客名・環境・監視基盤の稼働 n/n）、WORKSPACE: ダッシュボード / サービス一覧 / 統計・比較、MONITORING: エラー管理（未解決件数バッジ）/ 監視ログ / 監視ルール / 通知設定 / 対処手順 / ノード、下部: 収集器状態・バージョン。
- 上部バー: パンくず、環境表示（本番/STG の **表示のみ**。切替はしない。1 DB = 1 環境）、ログ検索（/ キー）、通知ベル（未確認件数）、ユーザー。
- 既存の `/admin` 側「運用監視」メニューは `/monitor` へのリンクに置き換える。

### 6.2 デザイントークン（モックアップから採用）

| トークン | ダーク | ライト |
| --- | --- | --- |
| bg / side / panel / panel2 / hover | #11151b / #151a21 / #1a2029 / #202733 / #252e3a | #f4f6f9 / #fff / #fff / #f5f7fa / #edf1f7 |
| border / border-soft | #303846 / #28313d | #dbe1e9 / #e9edf2 |
| text / muted / faint | #edf0f6 / #97a3b5 / #6f7d92 | #1a283b / #617186 / #778499 |
| blue（情報・選択） | #839fff / bg #25334b | #4963c6 / #eaf0ff |
| green（正常） | #71c9aa / #213a34 | #248465 / #e6f5ed |
| amber（警告・アクティブナビ） | #edbd7b / #3b3229 | #a67530 / #fff5e6 |
| red（エラー・重大） | #f18a95 / #3d2832 | #c54d5e / #fff0f2 |
| purple（通知） | #b69ae6 | #8660b6 |
| 角丸 / 影 / フォント | 12px / 0 12px 45px #0003 / Inter + Noto Sans JP、13px、数値は tabular-nums、ID・時刻は等幅 | 同左 |

- アクセシビリティ: フォーカスリング（2px blue）、Skip link、色だけに依存しない（アイコン＋ラベル）、`prefers-reduced-motion` 尊重、モバイルはサイドバーをドロワー化。
- CSS は Vite テーマ（`resources/css/filament/monitor/theme.css`）としてビルド。v1 の `_styles.blade.php` 同梱方式は廃止（パネル専用テーマなので再ビルド前提）。

### 6.3 画面ごとの仕様（モックアップの要素 → データの裏付け）

| 画面 | 要素 | 元データ | 備考 |
| --- | --- | --- | --- |
| ダッシュボード | 期間（24h / 7 日 / 30 日）、前期間比較、サービス絞込み | counters（hourly） | 全件 COUNT しない |
| | KPI: 監視実行数 / 正常判定率 / 未解決インシデント（重大・エラー・警告内訳）/ 通知成功率 ＋ スパークライン | counters: outcome_hourly, notification_hourly; incidents（未解決は索引で数える） | |
| | 重大障害バナー | 未解決 CRITICAL | |
| | 異常検知数の推移（今回 実線 / 前期間 破線）、応答時間に切替 | counters severity_hourly / probe_hourly | SVG 自前描画（外部 CDN 不使用） |
| | サービスの稼働状況（24 本の時間バー、正常/警告/障害） | 時間ごとの最悪 outcome（counters outcome_hourly） | 未監視サービスはグレーで「未監視」 |
| | 異常イベントの処理フロー（検知 → 重大度 → 通知判定 → 配送結果） | counters: severity, notification（queued/delivered/failed/suppressed） | 「重複抑制」= 同一案件の集約（occurrence_delta−1 の合計）＋抑止中 |
| | 対応が必要な問題（重大度順 5 件）、最新の監視ログ 5 件 | incidents / logs | |
| | ノードカード（CPU/メモリ/ディスク/負荷ゲージ、直近 30 分スパークライン） | server_metrics 5 分集約＋ローカル直読み（同居時） | |
| | 表示をカスタマイズ（セクションの表示/非表示、順序） | localStorage | 試読み失敗時は既定表示 |
| サービス一覧 | サービスごとの可用性（24h）、平均応答時間、監視実行数、監視対象ノード、稼働履歴バー、詳細ドロワー（統計へ・監視ログへ） | counters, probe, checks | 可用性 = 正常時間 ÷ 監視時間（未監視時間は除外） |
| 統計・比較 | 判定結果の内訳（ドーナツ）、期間比較の詳細（指標×今回/前期間/差分/変化率）、異常が発生しやすい時間帯（曜日×2 時間ヒートマップ）、サービス別の異常件数 | counters | 固定期間ではなく選択期間 |
| エラー管理 | v1 を踏襲。未解決/解決済みタブ、重大度順、同一障害をまとめて表示、行クリックでドロワー（概要・対応状況変更・対処手順へ）、詳細ページは v1 の「5 つの事実」構成をトークンに合わせて再設計 | incidents | 状態変更は v1 の IncidentService（楽観ロック・監査） |
| 監視ログ | 検索（内容・チェック名・エラー番号の前方一致）、重大度、サービス、ノード、期間、CSV エクスポート（表示中ページのみ、最大 100 行）、ドロワー詳細、ログ本文コピー | logs（キーセット） | 自由文全文検索は対象外のまま |
| 監視ルール | 第4章 | features / checks / targets / nodes | |
| 通知設定 | Slack（表示名・チャンネル名のみ。Webhook は env）、メール通知（新規: 代替通知先として SMTP。`MAIL_*` 利用）、外部の死活監視（URL・60 秒・3 回失敗）、通知の動作（同一障害をまとめる 5 分 / 復旧時にも通知 / **夜間の警告通知を抑制 22:00–07:00、重大・エラーは常に通知**）、通知配送の状態（直近 24h の送信対象/完了/失敗/成功率）、テスト通知 | config + ins_monitor_settings + notifications | 設定変更は monitor.delivery_admin、監査必須 |
| 対処手順 | RB カード一覧 → ドロワーで手順（症状・確認・分岐・復旧・検証）。RB-11〜18 を追加（11 自動発注、12 JX/発注送信、13 EOS 受信、14 統計集計、15 締め処理、16 検索同期、17 飛脚同期・集金・容器回収、18 外部連携取込） | runbooks | |
| ノード | ノード一覧（状態・CPU/メモリ/ディスク）、ノード詳細（グラフ、マウント別、プロセス、しきい値超過履歴） | server_metrics | |

### 6.4 モックアップにあって採用しないもの

- 環境の切替セレクト（本番/STG）: 1 DB = 1 環境のため表示のみ。
- 「MDM 伴助」「外部連携」サービス: 本顧客の対象外（サービス一覧は config の sites から生成）。
- デモ用の「デモ設定を保存」「サンプルデータ」表記。

## 7. 通知の追加仕様（メール・電話）

決定事項（2026-11-03）: メールは可能（宛先 admin@sakemaru.ai、24 時間送信）。夜間抑制は採用しない。代わりに夜間（22:00–08:00 JST）のエラー発生時は担当者へ **電話**（Twilio）で通知する。

### 7.1 通知経路の整理

| 経路 | 対象 | 時間帯 | 内容 |
| --- | --- | --- | --- |
| Slack（既存） | ERROR / CRITICAL の新規・再発・昇格・継続・復旧 | 24 時間 | v1 のまま |
| **メール**（新規） | ERROR / CRITICAL の新規・再発・昇格（継続・復旧は設定で選択、既定 OFF）＋ Slack が BLOCKED / 5 分以上未送信のときの代替 | 24 時間 | 宛先 `admin@sakemaru.ai`（設定で複数可）。件名 `[華 本番][蔵 WMS][ERROR] MON-000123 新規: 要約`。本文は Slack と同じビルダー（伏字済み）＋詳細 URL。`MAIL_*` 設定（SMTP）。送信は配送ワーカーが Slack と同じ lease 方式で行い、`ins_monitor_notifications.route_key = mail` として履歴・再試行・失敗を記録 |
| **電話**（新規、Twilio Programmable Voice） | ERROR / CRITICAL の新規・再発（対象重大度は設定、既定 CRITICAL と ERROR） | **22:00–08:00 JST のみ**（時間帯は設定） | 担当者名簿の順に発信。TwiML `<Say language="ja-JP">`（Amazon Polly 日本語音声）で「酒丸監視です。華 本番 蔵 でエラー MON-000123 が発生しました。内容: …。確認する場合は 1 を押してください」と読み上げ、`<Gather>` で 1 を受けたら Insights 側で **確認（ACKNOWLEDGED）** を記録し後続の発信を止める。応答なし・不在・1 以外は 2 分後に次の担当者へ。名簿を 1 巡しても確認が無ければ 15 分後に再度 1 巡（最大 3 巡）。同一案件・同一世代で 1 回の発信列（notification_key に kind=PHONE を含める） |

### 7.2 電話通知の設計

| 項目 | 内容 |
| --- | --- |
| テーブル | **`ins_monitor_contacts`**: id, name, phone_e164, order_no, enabled, active_from/active_to（任意の在席時間帯）, updated_user_id, reason。**`ins_monitor_call_attempts`**: notification_id, contact_id, attempt_no, call_sid, started_at, status（queued/ringing/answered/no-answer/busy/failed/acknowledged）, digits, duration_sec, sanitized_error |
| 認証情報 | `TWILIO_ACCOUNT_SID` / `TWILIO_AUTH_TOKEN` / `TWILIO_FROM_NUMBER` は env のみ。画面・DB・ログに出さない |
| 発信 | 配送ワーカー（`monitor:dispatch-notifications`）が PENDING の PHONE 通知を lease し、Twilio REST API（`Calls.json`）で発信。TwiML は Insights の署名付き URL（`/internal/monitor/voice/{token}`）が返す。本文には顧客名・環境・サービス・エラー番号・要約（80 文字以内、伏字済み）だけを含め、個人情報・SQL・URL を読み上げない |
| 結果受信 | Twilio の StatusCallback / Gather action を `/internal/monitor/voice/{token}/status` で受ける。**Twilio 署名（X-Twilio-Signature）を検証**し、token は通知 1 件ごとに発行・30 分で失効。1 が押された場合 `IncidentService::act('acknowledge', actor=担当者名)` を冪等キー付きで実行し、監査に「電話で確認」と残す |
| 失敗時 | Twilio API エラー（4xx）→ BLOCKED と自己監視 `MONITOR_NOTIFICATION_BLOCKED`（route=phone）。5xx/タイムアウト → 再試行（Slack と同じ待機列）。発信不能の間も Slack・メールは送る |
| 流量・費用 | 同時発信 1、1 案件あたり最大 3 巡 × 名簿人数。同時多発時は案件をまとめて 1 本の発信で「エラー N 件」と読み上げ、個別案件の発信は抑制（まとめ配送と同じ扱い） |
| テスト | `monitor:notify-test --route=phone --to=<contact>` と画面の「テスト発信」（delivery_admin のみ、監査あり）。本番番号への誤発信防止に `MONITOR_PHONE_ALLOW_ENV=production` のガード |
| 画面 | 通知設定: 電話通知の ON/OFF、時間帯、対象重大度、担当者名簿（順序の入替、在席時間）、発信履歴（誰に・いつ・結果・確認の有無） |

### 7.3 SMTP・Twilio の設定を管理画面で行う

決定事項: メール（SMTP）と Twilio の設定は `.env` ではなく **通知設定画面**で登録する（現在は未準備のため、画面から投入できること）。

| 項目 | 内容 |
| --- | --- |
| 保存先 | **`ins_monitor_secrets`**: id, secret_key（smtp.host / smtp.port / smtp.username / smtp.password / smtp.encryption / smtp.from_address / smtp.from_name / twilio.account_sid / twilio.auth_token / twilio.from_number / slack.webhook_url(任意)）, value_encrypted, updated_user_id, updated_name, updated_at |
| 暗号化 | 値は Laravel `Crypt`（APP_KEY, AES-256-CBC）で暗号化して保存。復号は配送ワーカーと「接続テスト」だけが行う。画面には **末尾 4 文字以外を伏字**で表示し、空欄で保存すると変更しない |
| 権限 | 閲覧・編集とも `monitor.delivery_admin` のみ。変更は監査（値は記録せず「変更あり」だけ） |
| 優先順位 | 画面の設定 > `.env`（`MAIL_*`, `TWILIO_*`）。`.env` に残っている場合は画面に「環境変数の値を使用中」と表示し、画面で上書きできる |
| 接続テスト | SMTP: 認証まで（EHLO/AUTH）を実行し送信はしない「接続テスト」と、admin@sakemaru.ai へ 1 通送る「テスト送信」。Twilio: アカウント情報取得（`GET /Accounts/{sid}`）の「接続テスト」と、指定番号への「テスト発信」 |
| 出力の禁止 | 復号値をログ・例外メッセージ・通知本文・監査・CSV に出さない（Redactor のキー一覧に `auth_token`, `smtp.password` を追加） |
| 退避 | 設定済みでも Slack 配送は独立して動く。SMTP/Twilio 未設定なら該当経路は「未設定」として配送をスキップし、画面バナーで表示（BLOCKED にはしない） |

### 7.4 通知の動作（設定）

| 設定 | 既定 | 内容 |
| --- | --- | --- |
| 同一障害をまとめる | ON（5 分） | 既存のまとめ配送に加え 5 分窓で集約 |
| 復旧時にも通知する | ON（Slack）/ OFF（メール） | 既存 RECOVERY |
| メール送信 | ON、24 時間 | 宛先 admin@sakemaru.ai |
| 電話発信 | ON、22:00–08:00 JST | 対象 CRITICAL・ERROR、名簿順 |
| 夜間の警告抑制 | **採用しない** | WARNING はもともと通知しない |

## 8. 変更するファイル（計画）

| 区分 | 追加・変更 |
| --- | --- |
| config | `monitoring.php`: sites 表示名、features 定義参照、probe/metrics 既定値、night_suppression |
| migrations | `2026_11_xx_create_ins_monitor_v2_tables.php`（features, nodes, targets, server_metrics, probe_results, contacts, call_attempts, secrets）、`..._alter_checks_add_feature_rule.php` |
| app/Monitoring/Services | FeatureRegistry（機能定義・検出 SQL）、TargetRegistry、MetricsStore（ローカル固定長ファイル）、MetricsAggregator（5 分集約）、ProbeRunner、StatsService（counters → KPI/推移/ヒートマップ/フロー）、MailNotifier、PhoneNotifier（Twilio）、VoiceCallbackController（署名検証・TwiML）、SecretStore（暗号化設定） |
| app/Monitoring/Checks | Wms/*, Core/*（external_collaboration 含む）, Search/*, Delivery/*（external_sync 含む）, Node/*, Http/ProbeCheck, Monitor/ProbeStaleCheck |
| app/Monitoring/Collectors | ResourceSampler（/proc）、BurstBuffer、MetricsFileWriter/Reader、ProcessWatcher |
| app/Console/Commands/Monitoring | detect-features, probe, metrics:aggregate, metrics:prune, targets:sync |
| app/Providers/Filament | `MonitorPanelProvider`（id monitor, path /monitor, sidebar, theme） |
| app/Filament/Monitor/Pages | Dashboard, Services, Analytics, Incidents, IncidentDetail, Logs, Rules, Notifications, Runbooks, Nodes, NodeDetail |
| resources/views/filament/monitor | layout partials（sidebar/topbar/drawer）、各ページ、SVG チャート部品 |
| resources/css/filament/monitor/theme.css | トークン・コンポーネント（ダーク/ライト） |
| deploy/monitoring | probe timer、metrics aggregate timer、sysstat/atop 有効化手順 |
| tests/Feature/Monitoring | 第10章の検証項目に対応 |

## 9. 実装手順（フェーズと順序）

各フェーズは「実装 → テスト → 画面確認（スクリーンショット）→ 本書の検証項目にチェック」で完了とする。

| # | フェーズ | 作業 | 完了条件 |
| --- | --- | --- | --- |
| P0 | 準備 | 表示名の日本語化（config, SeedDemo, 通知本文）。v1 テストが通ること | V-001〜003 |
| P1 | データモデル | v2 migration（features / nodes / targets / server_metrics / probe_results / contacts / call_attempts / secrets、checks 拡張、counters 拡張）。SQLite/MySQL 両方で up/down | V-010〜014 |
| P2 | 機能 ON/OFF | FeatureRegistry、features 同期、推奨値検出コマンド、CheckRunner のサービス→機能→項目の 3 段ゲート、未監視表示 | V-020〜027 |
| P3 | 新規チェック | 蔵（8 件）→ 勘定衆（6 件）→ 千里眼（3 件）→ 飛脚（5 件）。各チェックは NOT_DUE / UNKNOWN / HEALTHY / VIOLATION の 4 系統テスト（SQLite に業務テーブルの最小スタブを作成） | V-030〜046 |
| P4 | HTTP プローブ | targets(HTTP_PROBE)、ProbeRunner、probe_results、probe_hourly counters、probe_stale | V-050〜055 |
| P5 | 資源収集 | ResourceSampler、固定長ファイル、1 秒バースト、5 分集約、しきい値→エラー、60 日 prune、nodes 台帳 | V-060〜072 |
| P6 | 統計基盤 | counters 拡張（outcome/notification/probe）、StatsService（期間比較、ヒートマップ、フロー、可用性） | V-080〜086 |
| P7 | 画面刷新 1 | MonitorPanel、テーマ、サイドバー/トップバー/ドロワー、ダッシュボード、サービス一覧、統計・比較 | V-090〜099 |
| P8 | 画面刷新 2 | エラー管理・詳細、監視ログ、対処手順（RB-11〜17）、ノード | V-100〜109 |
| P9 | 画面刷新 3 | 監視ルール（3 段 ON/OFF・ルール編集・判定テスト・ノードと対象・変更履歴）、通知設定（メール・電話・担当者名簿・テスト通知） | V-110〜121, V-150〜160 |
| P9b | 各サービスのヘルスルート | 勘定衆・乃蓮・蔵・飛脚・千里眼に `/up` を追加（各リポジトリの小さな別コミット） | V-056 |
| P10 | 配置・移行 | systemd 追加、config の collectors → targets 移行コマンド、`/admin` メニューの付け替え、README 更新 | V-130〜134 |
| P11 | 総合検証 | 代表データで全画面スクリーンショット、性能（1,000 万ログ・100 万インシデントの隔離 MySQL）、v1 AC の再実行 | V-140〜146 |

実装時の注意（漏れ防止）:
1. 新しい check_key は必ず FeatureRegistry の機能に属させる（属さないチェックは起動時エラー）。
2. 新しい画面要素は必ず元データ（第6.3表）を持つ。元データが無い表示（モックのサンプル値）を残さない。
3. 業務DBアクセスは SourceReader 経由のみ。新規チェックで `DB::connection('monitor_source')` を直接使わない（静的検査でテスト）。
4. 表示名は `SiteRegistry::displayName()` から取得し、ハードコードしない。

## 10. 検証項目

形式: ID / 内容 / 合格条件 / 方法（U=Unit, F=Feature, M=手動/スクリーンショット, P=性能）

### 10.1 準備・命名
- V-001 全画面・通知・手順で「勘定衆/乃蓮/蔵/飛脚/千里眼/算盤」が表示され、旧名「酒丸（基幹）」等が残らない（F: assertSee / grep）
- V-002 Slack 本文の先頭が `[華 本番][蔵 WMS][ERROR]` 形式（F）
- V-003 v1 テスト 47 件が通る（F）

### 10.2 データモデル
- V-010 v2 migration が SQLite と MySQL で up/down できる（F, M）
- V-011 features に UNIQUE(site_id, feature_key)、targets のパス検証（許可外パス・glob・URL を 422）（F）
- V-012 server_metrics UNIQUE(node_id, bucket_start)、重複 INSERT が upsert される（F）
- V-013 checks.feature_key が FeatureRegistry と一致しないと sync が失敗する（U）
- V-014 既存 v1 データ（incidents/logs）が migration 後も参照できる（F）

### 10.3 機能 ON/OFF
- V-020 サービス無効 → 配下の全チェックが実行されず、画面は「未監視（サービス停止）」（F）
- V-021 機能無効 → 配下のチェックのみ実行されず「未監視（機能未使用）」。他機能は実行される（F）
- V-022 監視項目無効 → その項目のみ実行されず、check_stale の対象外（F）
- V-023 無効化・有効化に理由が必須、監査に before/after/理由/操作者が残る（F）
- V-024 monitor.view だけのユーザーは ON/OFF を変更できない（403）（F）
- V-025 推奨値検出: 業務テーブルにデータがある機能は recommended=true、無い機能は false、業務DB未接続は null（F: スタブ）
- V-026 推奨値と設定の不一致で「要確認」バッジが出る。通知は出ない（F）
- V-027 検出 SQL が各 1 秒上限・SELECT のみ（U: SourceReader 経由を静的検査）

### 10.4 新規チェック（各チェック共通 4 系統 + 固有）
- V-030 wms.auto_order_run: 有効倉庫なし→HEALTHY（対象なし）、slot 前→NOT_DUE、COMPLETED→HEALTHY、未完了→VIOLATION、failed_count>0→WARNING、preview 除外（F）
- V-031 wms.auto_order_calc: FAILED→VIOLATION（error_details を伏字で証拠）、RUNNING 60 分超→VIOLATION、PENDING 30 分→WARNING（F）
- V-032 wms.jx_order_generation: 予定推定（直近 7 日の generation_time）、予定＋30 分で未完了→VIOLATION、失敗→VIOLATION、予定なし→HEALTHY（対象なし）（F）
- V-033 wms.jx_transmission: 失敗→VIOLATION（指紋に日付を含めない、再発で件数加算）、送信ゼロ 48h（設定あり）→WARNING（F）
- V-034 wms.order_transmission: FAILED→VIOLATION、PENDING 60 分→WARNING、未確認 24h→WARNING（F）
- V-035 wms.eos_incoming_receive: 曜日・slot の有効スケジュールに対し receive_time＋30 分で判定、error_count>0→WARNING（F）
- V-036 wms.queue_failed / core / search / delivery の failed_jobs: exception 正規化で同一指紋、24h 外は対象外（F）
- V-037 core.stats_daily_aggregate: 期待間隔の推定（中央値）、×3＋猶予超で VIOLATION、failed→VIOLATION（F）
- V-038 core.stats_daily_finalize: 03:00 前→NOT_DUE、前営業日 succeeded→HEALTHY、無し→VIOLATION（F）
- V-039 core.stats_sales_performance: failed→VIOLATION、progress 停滞→WARNING（F）
- V-040 core.closing_process: failed→VIOLATION（指紋 process_type+closing_date）、completed→HEALTHY（F）
- V-041 search.sync_items_full: 04:00 以降で当日 success なし→VIOLATION、status error→VIOLATION（last_error を証拠）（F）
- V-042 search.sync_item_stocks: 45 分→WARNING、90 分→VIOLATION（F）
- V-043 delivery.operation_sync: FAILED_TO_SYNC→VIOLATION、READY/WAIT 60 分→WARNING、IN_PROGRESS 30 分→VIOLATION、前日未完了 06:00→VIOLATION（F）
- V-044 delivery.collected_money_check: 不正検知ステータス→WARNING（件数集約、日付あり）、48h 未確認→WARNING（F）
- V-045 delivery.container_collect: 配送あり・回収なし→WARNING、配送なし→HEALTHY（対象なし）（F）
- V-046 全新規チェックが業務DB未接続で UNKNOWN になり HEALTHY を返さない（F）
- V-047 delivery.external_sync: 運行 FINISHED あり・連携なし（02:30 以降）→VIOLATION、両 edi_type の APPLYING_SUCCESS あり→HEALTHY、VALIDATION_ERROR→VIOLATION、failed_count>0→WARNING、運行なし→HEALTHY（対象なし）（F）
- V-048 core.external_collaboration: パートナー別指紋、APPLY_WAIT 2h→WARNING、failed_count>0→WARNING（代表例を伏字で証拠）（F）

### 10.5 HTTP プローブ
- V-050 60 秒間隔、タイムアウト 5 秒、2xx 以外→VIOLATION（連続 3 回で ERROR、1 回は WARNING）（F: Http::fake）
- V-051 応答時間が probe_results と probe_hourly に記録される（F）
- V-052 プローブ停止 5 分で monitor.probe_stale が VIOLATION（F）
- V-053 URL は登録済みのみ。任意 URL を画面以外から指定できない（F）
- V-054 サービス一覧の可用性・平均応答時間が probe から計算される（F）
- V-055 プローブの User-Agent と送信先は顧客の自サービスのみ（U）
- V-056 各サービスの `/up` が 200 を返し、DB・セッションにアクセスしない（各リポジトリで F）

### 10.6 資源収集
- V-060 /proc のパースが固定サンプル（カーネル 5.x/6.x の /proc/stat・meminfo・diskstats）で正しい（U）
- V-061 30 秒間隔で 1 日 2,880 サンプル、ファイルサイズ ≈ 150KB（U: 時計を進めて検証）
- V-062 警告域で 1 秒バーストが前後 2 分を含めて書かれ、平常時は書かれない（U）
- V-063 日またぎでファイルが切り替わり、欠損時刻は NaN で埋まる（U）
- V-064 5 分集約が min/avg/max/last と sample_count を正しく出し、再実行で重複しない（F）
- V-065 60 日を過ぎた日別ファイルだけ削除され、DB の DELETE は発生しない（F）
- V-066 しきい値: CPU 90% が 5 分継続で VIOLATION、4 分では出ない。ディスク 85% は即時（F）
- V-067 連続 N 回正常で RECOVERED、1 回では戻らない（F）
- V-068 ノードカードのゲージ値が最新 5 分集約または直読みと一致（F）
- V-069 収集器停止で heartbeat 経由の WARNING/ERROR（既存）に加え、metrics 停止が自己監視に出る（F）
- V-070 `ss -s` が 60 秒に 1 回だけ実行される（U: モック）
- V-071 サンプラーの CPU 使用が 1 サンプル 5ms 未満（P: 1 万回計測）
- V-072 マウント別ディスクの除外（tmpfs, squashfs 等）が設定で効く（U）

### 10.7 統計基盤
- V-080 counters が severity/outcome/notification/probe で時間別に積み上がり、取込 1 回につき flush 1 回（F）
- V-081 期間比較: 今回/前期間の集計が同じ長さの期間で計算される（24h/7 日/30 日）（U）
- V-082 正常判定率 = 正常 ÷ 監視実行数（未監視は分母に含めない）（U）
- V-083 ヒートマップ（曜日×2 時間）が JST で集計される（U）
- V-084 処理フローの数値: 異常 = 警告＋エラー＋重大、通知対象 = 作成された通知、重複抑制 = 集約分、配送完了/失敗が notification と一致（F）
- V-085 ダッシュボードの全クエリが監視テーブルのみ・各 6 本以内・全件 COUNT なし（F: クエリログ）
- V-086 20 並列閲覧で業務DB SQL が 0 本（F）

### 10.8 画面刷新 1
- V-090 `/monitor` パネルが `/admin` と同じセッションでログイン状態を共有（F）
- V-091 ダーク/ライト切替が保存され、両テーマでコントラスト比 4.5:1 以上（M）
- V-092 サイドバーのアクティブ表示、未解決バッジ、収集器状態、モバイル幅でドロワー化（M）
- V-093 ダッシュボードの各セクションが第6.3表の元データを使い、サンプル値が残っていない（F: grep "サンプル"）
- V-094 期間切替・前期間比較・サービス絞込みが URL に反映される（F）
- V-095 重大障害バナーが未解決 CRITICAL にのみ出る（F）
- V-096 サービス稼働バー 24 本が時間ごとの最悪 outcome を表す。未監視は灰色（F）
- V-097 表示のカスタマイズが localStorage に保存され、失敗時も表示が壊れない（F/M）
- V-098 サービス一覧ドロワーから統計・監視ログへ条件付きで遷移（F）
- V-099 統計・比較のドーナツ・比較表・ヒートマップ・サービス別件数が同じ期間条件で一致（F）

### 10.9 画面刷新 2
- V-100 エラー管理タブ（未解決/解決済み）、重大度順、ドロワーの対応状況変更が IncidentService 経由（楽観ロック・監査）（F）
- V-101 エラー詳細の「5 つの事実」が新テーマで表示され、全アクション（確認/担当/開始/メモ/抑止/復旧確認/解決/終了/再オープン/再確認予約）が動く（F）
- V-102 監視ログの検索・絞込み・キーセットページング・新着表示が v1 と同等（F）
- V-103 CSV エクスポートは表示中ページのみ、伏字済み、UTF-8 BOM（F）
- V-104 ログ本文コピー（クリップボード API 失敗時のフォールバック表示）（M）
- V-105 対処手順 RB-11〜18 が登録され、各新規チェックのインシデントから参照される（F）
- V-106 ノード一覧・詳細のグラフが 24h/7 日/1 年で切替、範囲指定で 30 秒値・1 秒バーストが表示（F/M）
- V-107 ノード詳細のマウント別ディスク表・プロセス表（F）
- V-108 画面内のすべての時刻が JST 表記（F）
- V-109 キーボード操作（Tab/Enter/Esc）でドロワー開閉・行選択ができる（M）

### 10.10 画面刷新 3
- V-110 監視ルール「サービスと機能」ツリーの ON/OFF が features/checks に保存、理由必須（F）
- V-111 ルール編集（しきい値・比較条件・重大度・間隔・一致時の動作）が許可キーのみ保存、未実装チェックは有効化不可（F）
- V-112 判定テストが業務DBを叩かずに結果を返す（F: クエリログ）
- V-113 優先順位の上下が保存され、CheckRunner の実行順に反映（F）
- V-114 ノードと対象: ログパス登録の検証（許可ディレクトリ外・glob・URL を拒否）、HTTP URL の検証（https のみ、顧客ドメインのみ）（F）
- V-115 変更履歴タブが監査テーブルを表示（F）
- V-116 通知設定: Webhook URL・Twilio 認証情報を画面に表示しない・保存しない（F: grep）
- V-117 メール: ERROR/CRITICAL の新規・再発・昇格で admin@sakemaru.ai へ送信、Slack BLOCKED 時も送信、本文が伏字済み・件名形式が正しい（F: Mail::fake）
- V-118 メールの失敗が notifications（route=mail）に記録され再試行される。未送信を DELIVERED にしない（F）
- V-119 「同一障害をまとめる」5 分窓が重複通知を減らし、未掲載を DELIVERED にしない（F）
- V-120 テスト通知が delivery_admin のみ実行でき、監査に残る（F）
- V-121 通知配送の状態（24h）が notifications の実績と一致（F）

### 10.10b 電話通知（Twilio）
- V-150 22:00–08:00 JST の新規 CRITICAL/ERROR で PHONE 通知が作成され、日中は作成されない（境界 21:59/22:00/07:59/08:00）（F）
- V-151 担当者名簿の順に発信し、応答なし・busy・failed は 2 分後に次の担当者へ（F: Twilio クライアントをモック）
- V-152 Gather で 1 が押されると案件が ACKNOWLEDGED になり（actor=担当者名、監査「電話で確認」）、後続の発信が CANCELLED（F）
- V-153 名簿 1 巡で確認なし → 15 分後に再度 1 巡、最大 3 巡で停止し BLOCKED ではなく「未応答」として記録（F）
- V-154 StatusCallback の Twilio 署名が不正・token 失効・他案件の token を 401/403 で拒否（F）
- V-155 読み上げ本文に個人情報・SQL・URL・Webhook が含まれず 80 文字以内（U）
- V-156 Twilio 4xx → BLOCKED と自己監視、5xx → 再試行、発信不能でも Slack・メールは送られる（F）
- V-157 同時多発（5 件以上）は 1 本の発信にまとめ、個別発信を抑制。未掲載案件を DELIVERED にしない（F）
- V-158 `MONITOR_PHONE_ALLOW_ENV` 不一致の環境では発信せず、テスト発信も拒否（F）
- V-159 テスト発信が delivery_admin のみ、監査に残る（F）
- V-160 発信履歴画面に誰に・いつ・結果・確認の有無が表示される（F）

### 10.10c 管理画面での SMTP・Twilio 設定
- V-161 設定値が暗号化されて保存され、DB 上に平文が無い（F）
- V-162 画面表示は末尾 4 文字以外が伏字、空欄保存で値が変わらない（F）
- V-163 delivery_admin 以外は閲覧も編集も 403（F）
- V-164 画面設定が `.env` より優先され、未設定時は `.env` にフォールバックし、どちらも無ければ経路「未設定」でスキップ（BLOCKED にしない）（F）
- V-165 接続テスト（SMTP 認証のみ / Twilio アカウント取得）とテスト送信・発信が監査に残り、失敗理由に秘密値が含まれない（F）
- V-166 復号値が例外メッセージ・ログ・通知本文に出ない（F: 故意の失敗で検証）

### 10.10d ノードの自動登録
- V-167 収集器の初回 heartbeat で `.env` の node_code / role / 表示名から nodes 行が作られ、2 回目以降は更新のみ（F）
- V-168 node_code が未設定の収集器は登録されず、自己監視に `MONITOR_NODE_NOT_CONFIGURED`（F）
- V-169 画面で変更できるのは表示名・有効/無効・しきい値だけで、node_code は変更不可（F）

### 10.11 配置・移行
- V-130 config.collectors → targets の移行コマンドが冪等（F）
- V-131 systemd: probe/metrics-aggregate/metrics-prune の timer が追加され、owner ノードだけで有効化される（M）
- V-132 `/admin` の「運用監視」が `/monitor` へのリンクになる（F）
- V-133 `monitor:preflight` が v2 テーブル・features・nodes・targets・メール設定を検査する（F）
- V-134 README/README(deploy) が更新され、顧客追加手順（サイト同期→推奨値検出→確定→収集器・プローブ登録）が記載（M）

### 10.12 総合
- V-140 全画面スクリーンショット（ダーク/ライト、1440px/390px）を docs に保存（M）
- V-141 隔離 MySQL: 1,000 万ログで監視ログ 100 件 p95 200ms、100 万インシデントで一覧 p95 500ms（P）
- V-142 server_metrics 5 台×1 年（52 万行）でノード詳細 1 年グラフ p95 500ms（P）
- V-143 v1 の AC-01〜56 のうち自動化済みが全て通る（F）
- V-144 新規チェック全体の 5 分あたり SQL 時間が予算 5 秒内（P: 本番相当データ量の隔離 DB）
- V-145 サンプラー＋収集器の常駐メモリが 1 台 64MB 未満（P）
- V-146 顧客を 1 社追加する手順を実施し、30 分以内に「未監視」が正しく出る状態になる（M）

## 11. 未決事項（実装前に確認）

解決済み（2026-11-03）: 飛脚→勘定衆の連携先は `external_collaboration_data`（MOBILE_COLLECT / API）で確認。各サービスの `/up` は無いため標準ヘルスルートを追加する。メールは admin@sakemaru.ai へ 24 時間送信。夜間 22:00–08:00 は Twilio で担当者へ電話。`/monitor` の専用パネル分離は可。

1. 飛脚側の送信実装（`sakemaru-delivery` の item-deliveries 画面配下）を読み、送信失敗の痕跡テーブルがあれば `delivery.external_sync` に加える（実装時に該当リポジトリへのアクセスが必要）。
2. **正式スケジュールの確認（華様本番）** — 本番 DB の実績から次を仮置きした。各リポジトリの `routes/console.php` / `Console/Kernel.php` と本番 crontab で確定し、`config_json.slots` を更新する。
   - 蔵 JX 発注生成: 月〜土 13:30、日 23:35
   - 蔵 EOS 受信: 毎日 22:00、月曜 10:00 / 16:00（スケジュール表が正）
   - 蔵 自動発注: slot 01:00（11/03 初回、曜日運用は未確定）
   - 勘定衆 統計集計: 02:00（日次バッチ）, 07:00, 08:10, 09:00, 12:00, 16:00, 19:00。finalize 02:00–02:15、mixed 同期 02:20
   - 千里眼: 全件 03:00 頃、在庫差分 15 分おき
3. 電話通知の担当者名簿（氏名・番号・順序）と Twilio アカウント・発信元番号（画面から登録。読み上げ音声は Polly 日本語で確認）。
4. SMTP の接続情報（画面から登録）。
5. ノードは各サーバーの `.env`（`MONITOR_NODE_CODE` 等）で自己登録するため台帳の初期入力は不要。本番の Web/バッチサーバー台数と同居構成だけ確認する。
6. DB サーバーの資源監視を対象に含めるか（Aurora なら対象外）。
7. `/up` 追加の各リポジトリへの反映タイミング（別コミット・別デプロイ）。

## 12. 付録: 本番 DB で確認した根拠（2026-11-03）

| 観測 | 内容 |
| --- | --- |
| stats_import_runs | daily_aggregate/core_earning 2,602 回（10/20〜）、daily_finalize 15 回（最終 11/03 02:05）、daily_department_sync、すべて succeeded |
| srh_search_sync_states | searchable_item_stocks（最終 success 11/03 20:45、約 15 分おき）、searchable_items_full（最終 11/03 03:01） |
| dlv_operations | FINISHED 222、READY_TO_SYNC 15、STARTED 2、PRINTED_COLLECTION 1（10/20〜） |
| wms_eos_incoming_receive_runs | scheduled SUCCEEDED 18（最終 11/02 22:02） |
| wms_auto_order_runs | slot `2026-11-03:01:00` COMPLETED 11、preview 1 |
| wms_jx_transmission_logs | send/PutDocument/91 success 92、receive/GetDocument/90 success 79、他 success |
| wms_jx_order_generation_runs | 13:30 SUCCESS 51、23:35 SUCCESS 8 |
| wms_auto_order_job_controls | ORDER_CALC SUCCESS 2,046、SALES_BASED_CALC SUCCESS 112 |
| closing_processes | supplier_bill completed 538 / failed 14、buyer_bill completed 61 / failed 3（10/01〜） |
| 定時実行の実績 | stats_import_runs（10/27〜）: 02:00 566 回・07:00・08:10・09:00・12:00・16:00・19:00、finalize 02:00–02:15、mixed 02:20。wms_jx_order_generation_runs（10/04〜）: 13:30 月〜土、23:35 日曜。wms_eos_incoming_receive_runs: 22:00 毎日、月曜 10:00/16:00。wms_auto_order_runs: 01:00 slot 11 件（11/03） |
| external_collaboration_data | MOBILE_COLLECT/API: DEPOSIT 189、EMPTY_CONTAINER_PICKUP 2,107（すべて APPLYING_SUCCESS、最終 11/03 02:03）。他パートナー: SUMATYU EARNING failed 67、LOGILESS failed 51、KAISOKU_NET DEPOSIT failed 208 / VALIDATION_ERROR 1（10/20〜） |
