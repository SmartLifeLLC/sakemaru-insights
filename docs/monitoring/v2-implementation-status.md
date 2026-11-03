# 算盤 監視 v2 実装状況（2026-11-03）

仕様書: `spec-v2-monitoring-catalog-and-redesign.md`（v2.2）。実装手順 P0〜P11 のうち、各リポジトリへの `/up` 追加（P9b）以外を実装。
テスト: `php artisan test tests/Feature/Monitoring` → **129 件成功（3,940 アサーション）**。全体テストの失敗 1 件（`ExampleTest`: `/` が `/admin` へリダイレクト）は既存の挙動で今回の変更とは無関係。

## フェーズ別

| フェーズ | 内容 | 状態 | 主なファイル / 検証 |
| --- | --- | --- | --- |
| P0 | 表示名の日本語化（勘定衆・乃蓮・蔵・飛脚・千里眼・算盤）、Slack 見出し `[華 本番][蔵 WMS][ERROR]` | 済 | config/monitoring.php sites, SiteRegistry::label / V-001〜003 |
| P1 | v2 テーブル 8 種＋既存表の列追加、up/down | 済 | 2026_11_04_000001_create_ins_monitor_v2_tables.php / FeatureGateTest |
| P2 | サービス → 機能 → 監視項目の 3 段 ON/OFF、推奨値検出（自動では切替えない） | 済 | FeatureRegistry, monitor:detect-features / V-020〜027 |
| P3 | 新規チェック 22 種（蔵 8・勘定衆 6・千里眼 3・飛脚 5）＋キュー失敗 5 種、対処手順 RB-11〜20 | 済 | Checks/{Wms,Core,Search,Delivery,Common}, RunbookRegistry / BusinessChecksTest（V-030〜048） |
| P4 | HTTP プローブ（60 秒・5 秒・連続 3 回でエラー、許可ホストのみ、リダイレクト追わない）、probe_stale | 済 | ProbeRunner, TargetService, HttpProbeCheck / ProbeTest（V-050〜055） |
| P5 | 資源収集（/proc 直読み、平常 30 秒・警告域 1 秒＋前後 2 分、固定長 150KB/日、60 日、5 分集約 365 日）、しきい値判定、ノード自己登録 | 済 | Resources/*, monitor:sample-resources, /internal/monitor/metrics / ResourcesTest（V-060〜072） |
| P6 | 統計基盤（期間比較・ヒートマップ・処理フロー・稼働バー）、メール、電話（Twilio）、接続情報の暗号化保存 | 済 | StatsService, MailNotifier, PhoneNotifier, VoiceController, SecretStore / StatsTest, NotifyChannelsTest（V-080〜086, V-116〜121, V-150〜166） |
| P7〜P9 | `/monitor` パネル（モックアップ準拠、ダーク/ライト、モバイル）: ダッシュボード・サービス一覧・統計・エラー管理・詳細・監視ログ・監視ルール・通知設定・対処手順・ノード | 済 | app/Filament/Monitor, resources/views/monitor, resources/css/monitor/monitor.css / PagesTest, PageActionsTest |
| P9b | 各サービス（勘定衆・乃蓮・蔵・飛脚・千里眼）へ `/up` を追加 | **未**（各リポジトリの別コミット） | Laravel 11+ は `withRouting(health: '/up')` |
| P10 | systemd（resources / detect）、collectors → 監視対象台帳の同期、preflight 拡張、`/admin` メニューをリンクへ、旧 URL の転送 | 済 | deploy/monitoring, monitor:sync-targets, monitor:preflight |
| P11 | 全画面スクリーンショット確認（ダーク・ライト・モバイル） | 済（ローカル） | 本番相当データでの性能計測は未（下記） |

## 仕様からの調整・判断（実装時）

- **V-034（発注データの未確認警告）**: 本番では `wms_order_jx_documents.confirmed_at` が使われていないため、既定 OFF（`unconfirmed_warning`）。監視ルールで有効化できる。
- **HTTP プローブ / サーバー資源の実行場所**: 追加の常駐を増やさないため、checks timer（1 分）の中で動くチェック（`http.probe` / `node.resources`）として実装。観測は対象サービスに帰属させ、Slack 見出しは `[蔵 WMS]` になる。
- **毎分チェックのログ量**: 正常時は「全体で 1 行」にまとめ、継続中のインシデントがある対象だけ個別に正常を記録（復旧判定に必要なため）。
- **資源データの認証**: ノードは事前登録不要のため、配置単位の共通鍵 `MONITOR_METRICS_SECRET`（HMAC）＋ヘッダと本文のノードコード一致で受け付ける。
- **1 秒バースト**: 前 2 分を残すため、1 秒ごとに軽量読み取りをメモリに保持（ファイルには警告域の前後だけ書く）。1 サンプル 5ms 未満をテストで確認。
- **電話の未応答**: 名簿を 3 巡しても確認が無い場合は配送不可（BLOCKED）ではなく新しい状態「未応答（UNANSWERED）」。
- **メール・電話が未設定**: 通知は「取消（not_configured）」として残し、配送不可にはしない（画面に表示）。
- **HTTP プローブを登録したとき**: 対象サービスの「HTTP 死活」機能が無効なら、同じ理由で有効化する（監査に残る）。
- **旧画面**: `/admin/monitor/...` は `/monitor/...` へ転送する（Slack に残った旧リンク対策）。旧 Blade は削除。

## 追加（2026-11-03 23:40）: Web サーバー 1 台の停止検知

ELB の後ろでは、外部からの `/up`（HTTP プローブ）は生きているサーバーに振り分けられるため、1 台の停止を検知できない。
対策として、各サーバーの資源収集（`monitor:sample-resources`）が 1 分ごとに /proc を走査し、**Apache・PHP-FPM・supervisord の生存**を確認する（HTTP・virtual host・外部コマンドに依存しない）。

- 必須プロセスは各サーバーの `.env` `MONITOR_NODE_PROCESSES`（未設定なら役割の既定: web は 3 つ全部、batch は php-fpm と supervisord）。
- 必須プロセスが **5 分以上 0 件** → `node.resources` が **重大（CRITICAL）**（指紋 = ノード＋プロセス、日付なし。5 分未満の再起動・一時停止は数えない）。夜間は電話の対象。
- サーバーごと停止した場合は資源データが途絶え、`monitor.metrics_stale` が 10 分でエラー。
- ELB 経由の HTTP プローブは「利用者から見た可用性・応答時間」として残す（サーバー単位の検知には使わない）。
- 画面: ダッシュボード・ノード一覧のカードにプロセスのバッジ、ノード詳細に「プロセスの稼働」表。しきい値（秒・無効化）はノードごとに監視ルールで変更可。

## 本番前レビュー（2026-11-04、独立レビュー → 修正済み）

私の作業を見ていない別のエージェントに本番前提でレビューさせ、15 件の指摘のうち 13 件を修正、2 件は影響が小さいため記録のみ。監視テストは 137 件（SQLite / MariaDB 10.11 の両方）。

| # | 重大度 | 指摘 | 対応 |
| --- | --- | --- | --- |
| 1 | High | `config:cache` 後は `env()` が null になり、HMAC 鍵・Slack Webhook・収集器の署名が全滅する | config 構築時に秘密値の env を `monitoring.secret_values` へ写し、`Support\Env::get` 経由で読むよう全箇所を変更。config:cache 相当のテストを追加 |
| 2 | High | 夜間に 2〜4 件のエラーが続くと、担当者へ同時に複数本の電話がかかる | 配置あたり同時 1 列に統一。進行中の列があれば新規案件はそこへ合流（読み上げは「N 件」、1 で全件確認）。テスト追加 |
| 3 | Medium | 全員が在席時間外の瞬間に電話通知が永久に取り消される | 有効な担当者がいれば 5 分待って再判定（取り消さない） |
| 4 | Medium | 業務 DB に接続できないとき「予算超過」として誤記録される | 接続・セッション設定の失敗を `SourceUnavailable` として扱う |
| 5 | Medium | 期間の下限が無い業務 SELECT が 3 本 | 下限を追加（未完了運行 14 日、JX 無送信 silence×2、集金未確認 90 日）。本番の対象テーブルは最大 3.5 万行で 1 秒上限には遠い（information_schema で確認） |
| 6 | Medium | ルール編集で `slots` 等に不正な値を保存するとチェックが毎回例外になり休止する | キーの命名規則で型・形式を検証（HH:MM、整数の範囲、真偽値、曜日キー）。テスト追加 |
| 7 | Medium | 電話のまとめ（batch）が終了後も PENDING のまま残り削除されない | 列の終了時に状態と保持期限を確定 |
| 8 | Low | CSV の式インジェクション | 先頭が `= + - @` のセルを `'` で無害化 |
| 9 | Low | 画面に例外メッセージ（SQL 全文の可能性）をそのまま表示 | 想定内の例外だけ表示、それ以外は固定文言＋ `report()` |
| 10 | Low | 常駐サンプラの終了時に最大 5 分の集約を捨てる | 終了前に集約を確定して送信 |
| 11 | Low | 複数台で `MONITOR_CHECK_OWNER_NODE` 未設定だと全ノードが業務照合を二重実行 | preflight がノード 2 台以上で未設定ならエラー |
| 12 | Low | ノード詳細の 1 年表示で約 10 万行を毎回読む | DB 側で時間/日に集約（最大 400 点） |
| 13 | Low | `?from=` の不正な日時で 500 | 既定範囲へフォールバック |
| 14 | Low | 配送不可（BLOCKED）の通知が永久に残る | 保持期限（90 日）を付けて削除対象に |
| 15 | Low | 署名付き API の 5 分以内の再送に nonce が無い | 取込は event_id / (node, bucket) で冪等のため影響は heartbeat の状態上書きのみ。記録のみ |

## 追加（2026-11-04 00:30）: 通知・電話の多発防止

電話は人を起こす通知のため、回数を抑える制御を入れた（全て通知設定画面で変更可、0 で無効。上限を超えた分は Slack・メールだけになり、画面に「上限到達」と表示）。

| 制御 | 既定 | 動作 |
| --- | --- | --- |
| 発信前の猶予 `delay_minutes` | 5 分 | 作成から 5 分後に判定。その間に復旧（RECOVERED）または確認・対応開始されたら発信しない（`recovered_before_call` / `acknowledged_elsewhere`） |
| 発信列の間隔 `cooldown_minutes` | 30 分 | 前の列が終わってから 30 分は新しい列を始めない。その間に来た案件は待たせて 1 本にまとめる（読み上げ「N 件」） |
| 同じ案件 `per_incident_cooldown_hours` | 1 時間 | 一度電話した案件は 1 時間、再発・再検知しても電話しない（Slack・メールは送る） |
| 1 晩の列数 `max_columns_per_window` | 3 | 夜間帯（既定 22:00 開始）に始めた列が 3 に達したら、以後は `phone_capped` で取消 |
| 1 人の着信 `max_calls_per_contact_per_window` | 4 | 上限に達した担当者には発信しない。全員が上限なら列を「未応答」で終える（巡回を続けない） |
| 配置あたり同時 1 列 | 常時 | 進行中の列があれば新規案件はそこへ合流 |
| メール `min_interval_minutes` / `max_per_hour` | 5 分 / 20 通 | 間隔内の案件は 1 通にまとめ、上限超過分は翌時間枠へ持ち越す（捨てない） |
| Slack `max_per_hour` | 60 通 | 超過分はまとめ送信で翌時間枠へ（既存: 毎分 10 通・1 通に最大 20 件） |

**アラートの一時停止**: 上部バーの「アラート一時停止」ボタン（対応権限）で 30 分〜4 時間、Slack・メール・電話を止める（理由必須・監査あり・期限で自動再開・全ページにバナーと「再開」ボタン）。エラー詳細の「対応開始」にも同じ選択肢がある。停止中の通知は保留し、再開後にまとめて送る。停止中・猶予中に確認・対応中になった案件には電話しない。

通知設定画面に「今夜の発信列 n / 3」「次の発信 HH:MM 以降（間隔中）」「担当者ごとの今夜の着信回数」を表示する。

## 本番前に必要なこと

1. 各サービスへ `/up` を追加（P9b）し、HTTP プローブを登録。
2. 華様の本番スケジュールの確認（JX 発注データ生成・統計集計の正式な時刻）→ 監視ルールの `slots` を確定。
3. `npm run build`、v2 migration、`.env`（ノード・許可ホスト・公開 URL・権限）、接続情報（SMTP・Twilio・Slack）を画面で登録。
4. 担当者名簿の登録とテスト発信（`MONITOR_PHONE_ALLOW_ENV=production` の環境のみ発信）。Twilio の音声（Polly 日本語）の聞こえ方を確認。
5. MySQL での性能計測（V-085/V-140〜146: ダッシュボード 6 クエリ以内、1,000 万ログ、20 並列閲覧）。
6. 旧 Python 監視との shadow 運用（v1 から継続）。
