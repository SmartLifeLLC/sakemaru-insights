# 運用監視 — 現在の監視対象と次期改修案

作成日: 2026-11-03 / 対象: sakemaru-insights（仕様書 v1.1 初回実装後）

---

## 1. 現在の監視対象

### 1.1 業務照合チェック（owner ノードが実行、業務DBへ SELECT のみ）

| サイト | キー | 内容 | 判定開始 / 間隔 | 対処手順 | 状態 |
| --- | --- | --- | --- | --- | --- |
| TRADE | `trade.coupon_daily` | クーポン日別統計（店舗×企画）と元POS raw の照合 | 翌 00:50〜 / 30分 | RB-01 | 実装済。主指標 transaction_count、4指標厳密比較は `strict_metrics`（参照実装突合後に有効化） |
| TRADE | `trade.member_daily` | 元データがある店舗・営業日の会員日別統計行の存在 | 翌 00:45〜 / 30分 | RB-02 | 実装済 |
| TRADE | `trade.hourly_receive` | 時刻指定（target_hour）受信リクエストの完了。他経路（5分受信）成功ありは WARNING | 対象時刻＋1h＋15分 / 5分 | RB-03 | 実装済 |
| TRADE | `trade.transfer_pending` | 店舗同期・受信リクエストの滞留（PENDING/BEFORE_RUNNING 15分超、RUNNING 2h超） | 常時 / 5分 | RB-04 | 実装済 |
| TRADE | `trade.daily_transfer` | 日次締め受信（daily_close）の成功履歴 | 00:10＋2h / 15分 | RB-04 | 実装済 |
| TRADE | `trade.mail` | 有効な帳票メールスケジュールの送信完了 | 予定＋30分 / 10分 | RB-05 | 実装済 |
| TRADE | `trade.intage` | インテージPOS送信の完了 | 予定＋30分 / 10分 | RB-05 | 実装済 |
| TRADE | `trade.fixed_point` | 期間内の定点POSスケジュール実行（schedule_last_run_at） | 予定＋2h / 15分 | RB-05 | 実装済（IRMN 送信段階の判定は突合待ち） |
| TRADE | `trade.promo` | 販促レシートキャンペーンの send_at → sent_at | send_at＋2h / 15分 | RB-05 | 実装済 |
| TRADE | `trade.daily_report` | 日報処理（company_daily_sales_report_runs）の完了 | 設定時刻＋60分 / 15分 | RB-05 | 実装済 |
| TRADE | `trade.item_last_use` | 商品検索コード最終使用日の更新（23:00 バッチ） | — | RB-06 | **未実装**（保存先テーブル・列が未確認。画面に「未実装」と表示、HEALTHY にしない） |

共通事項:
- 業務DB接続 `monitor_source` が未設定のときは実行せず、`MONITOR_SOURCE_NOT_CONFIGURED` を記録して UNKNOWN のまま。
- 1 文 1 秒（軽量）/ 3 秒（明細）、5 分あたり SQL 時間 5 秒、1 起動 90 秒、同時 SELECT 1、3 回連続失敗で 15→30→60 分休止。
- 復旧条件: 日次精査系は完全一致 1 回、常時系は完全な正常確認 2 回連続（チェック定義に固定）。

### 1.2 アプリケーションログ収集（各ノードの収集器）

| 対象 | 内容 | 状態 |
| --- | --- | --- |
| TRADE / CORE / WMS / SEARCH / DELIVERY | Laravel ログの ERROR 以上（CRITICAL/ALERT/EMERGENCY 含む）。INFO は許可パターンのみ。複数行例外を 1 件に集約、同一指紋は 60 秒窓で件数集約 | 仕組みは実装済。`config/monitoring.php` の `collectors` に **ノード・ログパス・署名鍵が登録されたサイトだけ**が対象。現状は未登録＝「未監視」表示 |

### 1.3 Insights 自己監視（INSIGHTS サイト）

| キー | 内容 | 閾値 |
| --- | --- | --- |
| `monitor.collector_stale` | 登録収集器の heartbeat | 5 分超 WARNING / 10 分超 ERROR |
| `monitor.check_stale` | 有効チェックの最終完了が interval＋grace 内か | 超過 ERROR |
| `monitor.notification` | Slack 未送信の最古 / BLOCKED の存在 | 5 分超 ERROR / BLOCKED は ERROR |
| `monitor.storage` | 退避領域使用率、正常ログ削除遅延、緊急ファイル | 70% WARNING / 85% ERROR、遅延 24h 超 ERROR |

内部イベント: `MONITOR_DB_BUDGET_EXCEEDED`, `MONITOR_SOURCE_UNAVAILABLE`, `MONITOR_NOTIFICATION_BLOCKED`, `RETENTION_BACKLOG`, `CHECK_TIMED_OUT`, `CHECK_EXCEPTION`。

### 1.4 現在は対象外

- サーバー資源（CPU・メモリ・ディスク・負荷・I/O）
- CORE / WMS / SEARCH / DELIVERY の業務整合性チェック（アプリログのみ）
- HTTP 死活・外形監視
- サーバー全停止の検知（ホスト外死活監視 `MONITOR_EXTERNAL_HEARTBEAT_URL` の設定待ち）

---

## 2. 次期改修案

### 2.1 顧客別の監視対象設定（テーブル追加・管理画面）

1 DB = 1 顧客のため、監視テーブル（`ins_monitor_*`）自体が顧客別である。不足しているのは「何を・どこで・どの閾値で監視するか」をコードや config でなく画面から登録・変更できること。

**追加テーブル**

| テーブル | 内容 |
| --- | --- |
| `ins_monitor_nodes` | サーバー台帳: node_code、表示名、役割（web/batch/db/other）、設置サイト群、OS 情報（収集器から自動更新）、資源しきい値 JSON、有効/無効、最終 heartbeat |
| `ins_monitor_targets` | 監視対象の登録: kind（APPLICATION_LOG / SERVER_METRICS / HTTP_PROBE / BUSINESS_CHECK）、site_id、node_id、params JSON（ログパス・URL・マウントポイント等）、thresholds JSON、route_key（通知経路）、enabled、version、updated_by、reason |
| `ins_monitor_server_metrics` | 1 分集約（下記 2.3） |

**方針**

- 収集器のログパスは `config/monitoring.php` → `ins_monitor_targets` へ移す。パスは管理者が登録するが、サーバー側で許可ディレクトリ配下の絶対パスであることを検証し、glob・URL・シェルは受け付けない。
- 既存 `ins_monitor_checks`（業務照合）の有効化・猶予・許可設定値の編集も同じ管理画面に統合。
- 変更は全て `ins_monitor_audit_events` に理由付きで記録。編集は `monitor.manage` 権限のみ。
- 収集器は起動時に自ノードの targets を取得（同居なら直読み、別ホストなら署名付き API）し、60 秒ごとに差分を反映。

### 2.2 サーバー資源の監視

各サーバーに設置する収集器（既存 `monitor:collect-logs` と同じ常駐プロセス）が `/proc` と `statvfs` から **30 秒ごと**に取得する。外部ツール（sar/netstat 等）を毎回起動せず、それらが読んでいる `/proc` を直接読む。Netdata・AWS 連携は採用しない（必要時の調査用に限定）。

| 分類 | 指標 | 取得元 |
| --- | --- | --- |
| CPU | user / system / iowait / steal / idle（30 秒平均 %）、コア数 | `/proc/stat` |
| 負荷 | load average 1/5/15、実行中・総プロセス数、PSI（cpu/memory/io の待ち割合） | `/proc/loadavg`, `/proc/pressure/*` |
| メモリ | total / used / available / cached、swap used | `/proc/meminfo` |
| ディスク | マウント別 使用率・空き（GB）・inode 使用率、デバイス別 read/write IOPS・MB/s・util | `statvfs()`, `/proc/diskstats` |
| ネットワーク | IF 別 rx/tx bytes、errors/drops | `/proc/net/dev` |
| 接続 | ESTAB / TIME_WAIT 数、MySQL 向け接続数（60 秒に 1 回だけ `ss -s` を実行） | `ss` |
| プロセス | php-fpm / mysqld / queue worker の生存と数（登録したものだけ） | `/proc/<pid>` |

しきい値（例: ディスク 85% で ERROR、CPU 90% が 5 分継続、iowait 30% 継続、メモリ available 5% 未満、load > コア数×2）は `ins_monitor_nodes.thresholds` で顧客別に設定し、超過は既存のエラー管理（指紋: node + metric、日付なし）に流す。回復は連続 N 回の正常で RECOVERED。瞬間値ではなく継続時間で判定し、誤報を避ける。

**調査用の標準ツール（Insights とは連携しない）**: 各サーバーで sysstat（`sar`、10 秒間隔、60 日）と `atop`（60 秒間隔、30 日）を有効化し、対処手順に「`sar -s HH:MM -e HH:MM` / `atop -r` で当該時間帯を確認」と記載する。プロセス単位の原因特定は atop が担う。

### 2.3 保存設計（平常時 30 秒・異常時 1 秒バースト・60 日）

常時 1 秒は採用しない（エラー判定は継続時間で決まるため検知結果が変わらず、保存量と実装だけが増える）。

| 層 | 粒度 | 保持 | 置き場所 | 容量 / 負荷 |
| --- | --- | --- | --- | --- |
| 通常サンプル | **30 秒** | **60 日** | 各サーバーのローカル固定長バイナリ（日別ファイル、指標ごと float32 列） | 2,880 サンプル/日 × 約 50B ≒ 150KB/日 → **約 9MB / 60 日 / サーバー** |
| バースト詳細 | **1 秒** | 60 日（発生時のみ） | 同ファイルの別系列。収集器がメモリ上に直近 2 分の 1 秒値を保持し、警告域（例: CPU 70%、iowait 10%、available 15%）に入ったら前後 2 分を含めて書き出す | 異常時のみ。1 時間の異常で約 180KB |
| 集約 | **5 分（min / avg / max / last）** | 365 日 | `ins_monitor_server_metrics`（MySQL、ノード×5 分を 1 行） | 288 行/日/サーバー → 5 台で年 52 万行。書込みは 5 分に 1 INSERT |
| ライブ | 30 秒 | 直近 30 分 | ローカルファイル末尾（概要画面用） | — |

- 画面: 24h / 7 日 / 1 年は 5 分集約を MySQL から表示。30 秒値と 1 秒バーストは期間指定で該当ノードのローカルファイルから取得（同居なら直読み、別ホストなら HMAC 付き API）。
- 削除は 60 日を過ぎた日別ファイルの unlink のみ。DB の DELETE 負荷なし。5 分集約は既存の小分け削除（retention）に乗せる。
- サーバー消失時はローカル値も失われる（5 分集約は DB に残る）。1 分未満の詳細まで保全が必要なら日次で Insights 側へ退避（任意）。
- サーバー全停止の検知は、同一顧客に 2 台以上あればノード同士の heartbeat 監視（既存 `monitor.collector_stale`）、1 台構成は運用者の既存連絡手段（外形監視）を代替通知先として登録する。AWS 連携は必須にしない。

### 2.4 画面

- 概要: サイトカードに加えてノードカード（CPU / メモリ / ディスクのゲージ、負荷、heartbeat、直近 30 分スパークライン）
- ノード詳細: 24h / 7 日 / 1 年の切替グラフ（5 分集約）、範囲指定で 30 秒値と 1 秒バースト、マウント別ディスク表、しきい値超過の履歴（エラー管理へのリンク）
- 監視設定: ノード台帳、監視対象の登録・編集・無効化（理由必須）、しきい値編集、変更履歴

### 2.5 実装順

1. `ins_monitor_nodes` / `ins_monitor_targets` と管理画面、収集器の targets 読込み（ログパスの config からの移行）
2. 資源収集器（30 秒取得＋異常時 1 秒バースト、ローカル固定長ファイル 60 日、5 分集約の MySQL 書込み、しきい値→エラー連携）
3. 概要ノードカード・ノード詳細グラフ・ローカル値取得 API
4. 保持（60 日ファイル削除、5 分集約 365 日）と自己監視（資源収集の停止検知）、sysstat / atop 有効化手順

### 2.6 決定事項（2026-11-03）

- 常時 1 秒は採用せず、**平常時 30 秒・警告域で 1 秒バースト・ローカル保持 60 日**、DB には 5 分集約のみ。
- Netdata は本番に入れない（運用負荷・デーモン追加を避ける）。必要時の調査用に限定。
- AWS（CloudWatch）連携は必須にしない。Aurora 指標が必要になった場合のみ任意アダプター。
- 取得は `/proc` 直読み。sysstat / atop は調査用に各サーバーで有効化し Insights とは連携しない。

### 2.7 残る確認事項

1. 対象はWeb/バッチサーバーのみか、DB サーバーも含めるか（Aurora なら OS 指標は取得不可）。
2. 監視対象・しきい値の編集は `monitor.manage` 権限のみでよいか。
3. サーバー消失時のローカル値保全（Insights 側への日次退避）が必要か。
