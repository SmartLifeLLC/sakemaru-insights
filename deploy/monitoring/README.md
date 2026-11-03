# 運用監視 配置テンプレート（systemd）

監視対象DBごとに 1 つの配置（deployment_id）を持ち、unit 名・flock・退避・state のパスは
すべて `MONITOR_DEPLOYMENT_ID` で分離する。同じ Web サーバーに別 DB 向けの配置があっても
相互に停止・誤配送しない（仕様書 第16章）。

| unit | 役割 | 稼働ノード | 間隔 |
| --- | --- | --- | --- |
| `sakemaru-monitor-checks@<dep>.timer` | 業務照合・自己監視チェック（`monitor:run-checks`） | **owner ノードのみ** | 1 分 |
| `sakemaru-monitor-collect@<dep>.timer` | ファイルログ収集→退避→送信（`monitor:collect-logs`） | ログがある全ノード | 30 秒 |
| `sakemaru-monitor-notify@<dep>.service` | 再通知作成と Slack 配送（`monitor:dispatch-notifications --loop=50`） | owner ノードのみ | 常駐（10 秒ループ） |
| `sakemaru-monitor-retention@<dep>.timer` | 期限到来データの小分け削除（`monitor:retention`） | owner ノードのみ | 15 分 |
| `sakemaru-monitor-health@<dep>.timer` | ホスト外死活監視への heartbeat（`monitor:health --external-heartbeat`） | owner ノード | 1 分 |
| `sakemaru-monitor-resources@<dep>.service` | **v2** サーバー資源の収集（`monitor:sample-resources`、/proc 直読み。平常 30 秒・警告域 1 秒） | **全ノード** | 常駐（1 日 1 回入替） |
| `sakemaru-monitor-detect@<dep>.timer` | **v2** 機能の推奨値検出・監視対象台帳の同期（設定は変えない） | owner ノードのみ | 毎日 05:17 |

v2 の HTTP プローブ（`http.probe`）とサーバー資源の判定（`node.resources`）は checks timer の中で動く（追加 unit 不要）。
電話通知（Twilio）とメールは notify service が Slack と同じ lease 方式で配送する。

`<dep>` は `MONITOR_DEPLOYMENT_ID`（例: `hana-prod`）。`sakemaru-monitor@.slice` でホスト全体の
上限（MemoryMax=256M / CPUQuota=10%）を配置群に配分する。

## 手順（本番）

1. `php artisan monitor:preflight` で接続分離・権限・通知経路・ログパスを確認する（秘密値は表示しない）。
2. `php artisan migrate --path=database/migrations/2026_11_03_000001_create_ins_monitor_tables.php`（ins_monitor_* のみ）。
3. `.env` に以下を設定する（値は本書に書かない）。**`.env` を変えたら `php artisan config:cache` を再実行する**（秘密値は config 構築時に解決され、実行時に `.env` は読まない）。
   - `MONITOR_DEPLOYMENT_ID`, `MONITOR_CLIENT_NAME`, `MONITOR_ENVIRONMENT=production`
   - `MONITOR_NODE_CODE`（ノードごと）, `MONITOR_CHECK_OWNER_NODE`（1 ノードだけ）
   - `MONITOR_SOURCE_DATABASE/USERNAME/PASSWORD`（SELECT 専用ユーザー推奨）
   - `MONITOR_SLACK_ERROR_WEBHOOK`, `MONITOR_SLACK_ERROR_CHANNEL_NAME`
   - `MONITOR_COLLECTOR_SECRET_<APP>_<NODE>`（収集器ごとの署名鍵）
   - `MONITOR_FALLBACK_CONTACT`, `MONITOR_EXTERNAL_HEARTBEAT_URL`
4. `config/monitoring.php` の `collectors` に、サイト・ノード・ログの絶対パス・鍵の env 参照名を登録する。
5. unit をインストールし、owner ノードだけ checks / notify / retention を有効化する。

### v2 の追加手順（2026-11 以降）

1. `php artisan migrate --path=database/migrations/2026_11_04_000001_create_ins_monitor_v2_tables.php`
2. `npm run build`（`/monitor` パネルのテーマ `resources/css/monitor/monitor.css` を含む）
3. `.env`（各サーバー）: `MONITOR_NODE_CODE` / `MONITOR_NODE_ROLE`（web/batch/db/other）/ `MONITOR_NODE_DISPLAY_NAME` / `MONITOR_NODE_PROCESSES`（必須プロセス。例 `apache,php-fpm,supervisor`）。
   ELB の後ろでは外部からの `/up` で 1 台の停止を検知できないため、各サーバーの資源収集が Apache・PHP-FPM・supervisord の生存を /proc で 1 分ごとに確認する（5 分以上 0 件で重大）。
   別サーバーから送る場合は `MONITOR_INGEST_URL` と、同じ顧客の全ノード共通の `MONITOR_METRICS_SECRET`。
4. `.env`（owner）: `MONITOR_PROBE_ALLOWED_HOSTS`（自サービスのホストをカンマ区切り。例 `wms.lw-hana.net,.lw-hana.net`）、
   `MONITOR_PUBLIC_BASE_URL`（Twilio から届く Insights の URL）、`MONITOR_PHONE_ALLOW_ENV=production`、
   権限 `MONITOR_MANAGERS`（監視ルールの変更）/ `MONITOR_DELIVERY_ADMINS`（通知設定・接続情報）。
5. SMTP・Twilio・Slack Webhook は `/monitor/notifications` の「接続情報を編集」で登録する（暗号化保存、画面 > .env）。
6. `php artisan monitor:detect-features` で推奨値を検出し、`/monitor/rules` で顧客が使う機能だけを有効化する（理由必須）。
7. HTTP プローブを `/monitor/rules` → ノードと対象 → 「HTTP プローブを追加」で登録する（各サービスに `/up` が必要）。
8. `php artisan monitor:sample-resources --once` で /proc の読み取りを確認 → `sakemaru-monitor-resources@<dep>.service` を全ノードで有効化。
9. `php artisan monitor:notify-test --route=mail` / `--route=phone --to=<担当者>` で経路を確認（監査に残る）。
10. 調査用に sysstat（10 秒・60 日）と atop（60 秒・30 日）を有効化してよい（Insights とは連携しない。RB-19 参照）。

```bash
sudo systemctl enable --now sakemaru-monitor-resources@hana-prod.service       # 全ノード
sudo systemctl enable --now sakemaru-monitor-detect@hana-prod.timer            # owner のみ
```

```bash
sudo cp deploy/monitoring/*.service deploy/monitoring/*.timer deploy/monitoring/*.slice /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sakemaru-monitor-collect@hana-prod.timer          # 全ノード
sudo systemctl enable --now sakemaru-monitor-checks@hana-prod.timer           # owner のみ
sudo systemctl enable --now sakemaru-monitor-notify@hana-prod.service         # owner のみ
sudo systemctl enable --now sakemaru-monitor-retention@hana-prod.timer        # owner のみ
sudo systemctl enable --now sakemaru-monitor-health@hana-prod.timer           # owner のみ
```

6. shadow 運用: 旧 Python 監視（`sakemaru-trade-batch-monitor.timer`）を止めずに 1 営業日以上比較する。
   新側の業務照合は負荷予算内で動くが、同じ DB への重い照合を二重に走らせないよう、比較期間は
   `trade.coupon_daily` / `trade.member_daily` の interval を広げるか対象日を分担する。
7. 通知担当の切替: 新通知の実受信・エラー画面・ホスト外死活確認後に旧通知を停止する。

## 切戻し

- 新監視停止: `systemctl disable --now sakemaru-monitor-{checks,notify,retention,collect}@hana-prod.*`
  退避ファイル（`storage/monitor/spool`）と ins_monitor_* は保持する（DROP しない）。
- 旧通知復帰: 旧 timer を再度有効化。

## owner 交代

旧 owner の checks/notify/retention 停止確認 → `.env` の `MONITOR_CHECK_OWNER_NODE` 変更 →
最後の run_id・退避状態の確認 → 新 owner で起動 → ホスト外 heartbeat 確認。失踪ノードを
確認せずに二重起動しない。
