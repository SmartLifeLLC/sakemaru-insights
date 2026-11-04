# 算盤 監視 v2 リリース手順（華様 本番）

作成: 2026-11-03 / 対象: sakemaru-insights（監視 v1＋v2 を同時に初回リリース）/ 前提: 本番には `ins_monitor_*` テーブルがまだ無い。

原則: **業務 DB には SELECT 専用ユーザーでしか触れない。`migrate:fresh` / `refresh` / `reset` / `db:wipe` は使わない。秘密値は画面から登録し、手順書・チャット・ログに書かない。**

---

## 0. 用語と配置

| 用語 | 意味 |
| --- | --- |
| owner ノード | 業務照合チェック・通知配送・削除を動かす 1 台（`MONITOR_CHECK_OWNER_NODE`）。他のノードでは `monitor:run-checks` を拒否する |
| ノード | 各サーバー。`.env` の `MONITOR_NODE_CODE` で識別。資源収集の初回送信で自動登録 |
| 配置 ID | `MONITOR_DEPLOYMENT_ID`（例 `hana-prod`）。systemd unit 名・退避・ロックの分離単位 |

華様の構成（要確認・記入）: Web サーバー __ 台（ELB 配下）、バッチ __ 台、DB（Aurora）。算盤（Insights）が動くサーバー = owner ノード候補。

---

## 1. リリース 1 週間前まで

### 1.1 コードの確定
- [x] 独立レビュー（2026-11-04）の指摘 15 件のうち 13 件を修正済み（`v2-implementation-status.md` の表）。残り 2 件は記録のみ。
- [ ] 修正後のコードで`php artisan test` が通る（SQLite と MySQL の両方。MySQL は `MONITOR_TEST_MYSQL=1` と `DB_*` を指定して `tests/Feature/Monitoring` を実行）。
- [ ] コミット・タグ付け（例 `monitoring-v2.0`）。コミットは指示があるまで行わない。
- [ ] `npm run build` の成果物（`public/build/`）を配置する方法を、既存の算盤のデプロイ手順と合わせる（ビルドサーバー / デプロイ先で実行）。

### 1.2 各サービスへ `/up` を追加（P9b、別リポジトリ・別コミット）
- [ ] 勘定衆・乃蓮・蔵・飛脚・千里眼の `bootstrap/app.php` に `->withRouting(..., health: '/up')`（Laravel 11 以降）。旧バージョンは `routes/web.php` に DB・セッションに触れない軽量ルート 1 本。
- [ ] 認証不要・GET のみ・本文固定。Apache のアクセスログから除外してよい。
- [ ] ELB のヘルスチェックも `/up` に向ける（壊れたサーバーへの振り分けは ELB が止め、どのサーバーかは算盤が知らせる分担）。
- [ ] STG で `curl -sS https://<service>/up` が 200 を返す。

### 1.3 華様に確認する運用情報
- [ ] JX 発注データ生成の正式スケジュール（仮置き: 月〜土 13:30、日 23:35）→ `wms.jx_order_generation` の `slots`。
- [ ] 統計の定時処理の枠（Core `HanaSchedule`: 再集計 02:00/07:00/08:10/09:00/12:00/16:00/19:00、確定 02:00、部門同期 02:20、月次 15:00）→ `core.stats_job_slot` の `slots_*`。Core 側に `feature/scheduled-job-runs`（実行記録テーブル＋計装）のリリースが必要。未導入の間は「確認不可」。
- [ ] 自動発注の枠（仮置き 01:00）、EOS 受信（スケジュール表から自動）。
- [ ] 華様が使っている機能（蔵の JX / EOS、飛脚の集金・容器回収など）。`monitor:detect-features` の推奨値と突き合わせる。
- [ ] 電話をかける担当者の名簿（氏名・番号・順序・在席時間）と、夜間の時間帯（既定 22:00–08:00）。
- [ ] メールの宛先（既定 admin@sakemaru.ai）。
- [ ] Slack のエラーチャンネル名と Webhook（本番専用。STG と分ける）。

### 1.4 用意するアカウント・接続
- [ ] 業務 DB の **SELECT 専用ユーザー**（`monitor_source` 用）。`SHOW GRANTS` で INSERT/UPDATE/DELETE/ALTER が無いことを確認。
- [ ] SMTP アカウント（送信元 `monitor@sakemaru.ai` 等）。
- [ ] Twilio アカウント（Account SID / Auth Token / 発信元番号。日本の番号は事前登録が必要）。読み上げは Polly 日本語（`Polly.Mizuki`）。
- [ ] Twilio から算盤へ届く公開 URL（`MONITOR_PUBLIC_BASE_URL`）。`POST /internal/monitor/voice/*` が WAF・IP 制限で止まらないこと（署名検証で守る）。
- [ ] 各サーバーから算盤へ資源データを送る経路（`MONITOR_INGEST_URL`）。同じサーバーなら不要。

### 1.5 STG リハーサル
- [ ] 本手順 2〜4 を STG で一度通す。STG では `MONITOR_PHONE_ALLOW_ENV` を本番と違う値にし、Slack も STG チャンネル。
- [ ] `monitor:seed-demo` は STG 限定（本番では拒否される）。

---

## 2. リリース当日（切替前）

### 2.1 事前確認（owner ノード）
```bash
php artisan monitor:preflight          # 接続分離・権限・v2 テーブル・通知経路・収集器。秘密値は出ない
```
- `monitor_source grants: SELECT 専用 OK` であること。
- この時点では `ins_monitor_*` が無いので「migration 未実行」の警告は想定内。

### 2.2 バックアップ
- [ ] 算盤 DB のスナップショット（Aurora）。`ins_monitor_*` は新規テーブルだが、他の migration が同時に走らないよう `--path` 指定で実行する。

### 2.3 コードと `.env`
- [ ] 全サーバーへコードを配置（既存のデプロイ手順）。`public/build/` を含める。
- [ ] `composer install --no-dev`、`php artisan config:cache`、`php artisan route:cache`、`php artisan view:cache`（既存手順に従う）。**`.env` を書き換えたら必ず `config:cache` を再実行**（秘密値は config に写され、実行時に `.env` は読まない）。
- [ ] `.env`（**owner ノード**）:
  ```
  MONITOR_ENABLED=true
  MONITOR_DEPLOYMENT_ID=hana-prod
  MONITOR_CLIENT_NAME=華
  MONITOR_ENVIRONMENT=production
  MONITOR_NODE_CODE=<このサーバー>          MONITOR_NODE_ROLE=web|batch
  MONITOR_NODE_DISPLAY_NAME=<表示名>       MONITOR_NODE_PROCESSES=apache,php-fpm,supervisor
  MONITOR_CHECK_OWNER_NODE=<このサーバー>
  MONITOR_SOURCE_DATABASE=<業務DB>  MONITOR_SOURCE_USERNAME=<SELECT専用>  MONITOR_SOURCE_PASSWORD=<…>
  MONITOR_SLACK_ERROR_CHANNEL_NAME=#<本番チャンネル>
  MONITOR_PROBE_ALLOWED_HOSTS=<自サービスのホスト。例 wms.lw-hana.net,.lw-hana.net>
  MONITOR_PUBLIC_BASE_URL=https://<算盤の公開URL>
  MONITOR_PHONE_ALLOW_ENV=production
  MONITOR_MAIL_TO=admin@sakemaru.ai
  MONITOR_MANAGERS=<監視ルールを変更できる人の email（カンマ区切り）>
  MONITOR_DELIVERY_ADMINS=<通知設定・接続情報を扱える人の email>
  MONITOR_FALLBACK_CONTACT=<Slack 障害時の連絡先>
  MONITOR_METRICS_SECRET=<同じ顧客の全ノードで共通の長いランダム文字列>（別サーバーから送る場合のみ）
  ```
  Slack Webhook・SMTP・Twilio は **画面から登録**する（`.env` に書いてもよいが画面の設定が優先）。
- [ ] `.env`（**他のノード**）: `MONITOR_DEPLOYMENT_ID` / `MONITOR_NODE_CODE` / `MONITOR_NODE_ROLE` / `MONITOR_NODE_DISPLAY_NAME` / `MONITOR_NODE_PROCESSES` / `MONITOR_INGEST_URL` / `MONITOR_METRICS_SECRET`。`MONITOR_CHECK_OWNER_NODE` は owner と同じ値（別名を書くと拒否されるだけで害はない）。
- [ ] 収集器（アプリログ）: 各サーバーの `.env` に `MONITOR_LOG_COLLECTORS="WMS=/var/www/hana/sakemaru-wms/storage/logs/laravel*.log;TRADE=…"`（そのサーバーにあるアプリのログだけ。ワイルドカードで日次ログに対応）。同一ホストで取り込むので署名鍵は不要（別サーバーへ送る `MONITOR_INGEST_URL` を使う場合のみ `MONITOR_COLLECTOR_SECRET_<APP>_<NODE>`）。`config:cache` 後に `monitor:preflight` で readable=yes を確認し、`sakemaru-monitor-collect@hana-prod.timer` を有効化。

### 2.4 migration（owner ノード、`ins_monitor_*` だけ）
```bash
php artisan migrate --database=monitor_store --path=database/migrations/2026_11_03_000001_create_ins_monitor_tables.php --force
php artisan migrate --database=monitor_store --path=database/migrations/2026_11_04_000001_create_ins_monitor_v2_tables.php --force
php artisan monitor:preflight     # → sites / checks / features が同期され、v2 テーブルの警告が消える
```

### 2.5 画面からの初期設定（`https://<算盤>/monitor`、配送管理者でログイン）
1. 通知設定 → 「接続情報を編集」: Slack Webhook / SMTP（host, port, user, password, encryption, from）/ Twilio（SID, token, 発信元）。保存後は末尾 4 文字だけ表示される。
2. 通知設定 → 「接続テスト」（SMTP / Twilio）→ 「テスト送信」（メール）→ Slack「テスト通知」。
3. 通知設定 → 担当者名簿を登録し、1 名へ「テスト発信」（本番番号に実際にかかる。相手に事前連絡）。
4. 監視ルール → 「推奨値を検出」→ サービスと機能で、華様が使う機能だけ ON（理由を記入）。`slots` は 1.3 で確認した値をルール編集で設定。
5. 監視ルール → ノードと対象 → 「HTTP プローブを追加」（各サービスの `/up`、https のみ）。

### 2.6 常駐・timer の起動
```bash
sudo cp deploy/monitoring/*.service deploy/monitoring/*.timer deploy/monitoring/*.slice /etc/systemd/system/
sudo systemctl daemon-reload
# 全ノード
php artisan monitor:sample-resources --once          # /proc が読めて、必須プロセスが揃っているか（警告が出たら role / MONITOR_NODE_PROCESSES を見直す）
sudo systemctl enable --now sakemaru-monitor-resources@hana-prod.service
sudo systemctl enable --now sakemaru-monitor-collect@hana-prod.timer      # アプリログ収集を使うノードだけ
# owner ノードだけ
sudo systemctl enable --now sakemaru-monitor-checks@hana-prod.timer
sudo systemctl enable --now sakemaru-monitor-notify@hana-prod.service
sudo systemctl enable --now sakemaru-monitor-retention@hana-prod.timer
sudo systemctl enable --now sakemaru-monitor-health@hana-prod.timer
sudo systemctl enable --now sakemaru-monitor-detect@hana-prod.timer
```

---

## 3. リリース直後の検証（30 分以内）

| # | 確認 | 期待 |
| --- | --- | --- |
| 1 | `php artisan monitor:health` | 収集器・実行器・通知待ち・容量が表示され、`MONITOR_EMERGENCY` が無い |
| 2 | `/monitor` ダッシュボード | 上部の警告バナーが消えている（Webhook・実行器・代替連絡先・ホスト外死活）。KPI が 5 分以内に埋まる |
| 3 | ノード | 全サーバーがカードに出て、Apache / PHP-FPM / supervisord が緑。受信が「1 分前」以内 |
| 4 | サービス一覧 | HTTP プローブの可用性と応答時間が出る。使っていない機能は「未監視（機能未使用）」 |
| 5 | 監視ログ | `MONITOR_SOURCE_NOT_CONFIGURED` が出ていない。業務チェックが HEALTHY / NOT_DUE / WARNING で記録される |
| 6 | エラー管理 | 既知の問題以外に新規の ERROR が無い。あれば内容を確認（初回は過去分の検知があり得る） |
| 7 | Slack | テスト通知と、初回の ERROR（あれば）が `[華 本番][蔵 WMS][ERROR]` 形式で届く。詳細 URL が `/monitor/incidents/MON-…` を指す |
| 8 | メール | テスト送信が admin@sakemaru.ai に届く |
| 9 | 電話 | テスト発信がつながり、日本語の読み上げが聞ける。1 を押すと「確認を記録しました」 |
| 10 | `journalctl -u sakemaru-monitor-resources@hana-prod -n 20` | 送信失敗のログが無い |
| 11 | 負荷 | 業務 DB の `SHOW PROCESSLIST` で監視ユーザーの長いクエリが無い（1 秒上限）。サーバーの CPU 増分が 1% 未満 |

---

## 4. shadow 運用（1 営業日以上）

- 旧 Python 監視は止めない。両方の検知を 1 営業日以上比べ、差分（新側だけ・旧側だけ）の理由を記録する。
- 翌朝（02:00〜09:00 の枠を通過後）に確認: 統計集計・日次確定・自動発注・JX 生成・検索全件更新が HEALTHY になっているか。NOT_DUE のままの項目は `slots` の見直し。
- 夜間に ERROR が出た場合、電話の発信履歴（通知設定）で「誰に・いつ・結果」を確認。
- 問題がなければ旧通知を停止し、通知担当を切り替える。

---

## 5. 切戻し

1. `sudo systemctl disable --now sakemaru-monitor-{checks,notify,retention,health,detect}@hana-prod.*`（owner）、`sakemaru-monitor-{resources,collect}@hana-prod.*`（全ノード）。
2. 旧 Python 監視の timer を有効化（止めていた場合）。
3. コードを前のタグへ戻す。`ins_monitor_*` と `storage/monitor/` は **削除しない**（DROP しない。再開時にそのまま使う）。
4. `/admin` の「運用監視」リンクは前のコードに戻せば消える。

---

## 6. 運用で決めておくこと

- 権限: `MONITOR_MANAGERS`（監視ルール）・`MONITOR_DELIVERY_ADMINS`（通知・接続情報）の担当者。変更は全て理由必須で監査に残る。
- メンテナンスでサーバーを ELB から外すとき: 監視ルール → ノードでそのノードを「無効」にする（プロセス停止・資源停止の誤報を防ぐ）。
- APP_KEY を変えると接続情報（暗号化保存）が復号できなくなる。変える場合は先に画面で再登録する。
- 調査用に各サーバーで sysstat（10 秒・60 日）と atop（60 秒・30 日）を有効化してよい（算盤とは連携しない）。
