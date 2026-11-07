<?php

namespace App\Monitoring\Services;

use App\Models\Monitoring\Runbook;
use Carbon\CarbonImmutable;

/**
 * 対処手順（仕様書 第14章）。コードで版管理し DB へ同期する。手順中のコマンドは説明用で画面から実行しない。
 */
class RunbookRegistry
{
    public const VERSION = 5;

    /** @return array<string,array<string,string>> */
    public static function definitions(): array
    {
        return [
            'RB-01' => [
                'title' => 'クーポン日別統計の欠落・不一致',
                'symptoms' => '元POSにクーポン使用実績があるのに、店舗・企画別の日別統計（usage_count / coupon_quantity / transaction_count / item_discount_amount）が0または不一致。',
                'impact' => '該当営業日のクーポン分析・会員統計の信頼性。売上伝票・請求書には影響しない。',
                'first_checks' => "1. 対象営業日と店舗コード（ゼロ埋め差に注意）\n2. 元POS受信（tm_received_source_files / tm_receive_requests）の完了\n3. 期待4指標と保存行（ret_app_coupon_usage_daily_statistics）",
                'read_only_checks' => "・受信済みファイルの parse_status と business_date\n・raw_sale_items の coupon_jan_code 別件数（店舗・日付）\n・統計行の aggregated_at / aggregation_version",
                'decision_steps' => "A. 受信未完了 → 受信完了後に自動再集計されるか確認（RB-03/04）\nB. 受信完了・統計0 → 集計ジョブの実行履歴と排他ロック競合を確認\nC. 一部企画のみ不一致 → 企画マスター（instore_code）の対応を確認",
                'recovery_procedure' => '補正は対象日と件数をレビューした上で別作業として実施する。決算済み期間（9月以前）は含めない。監視画面からは実行しない。',
                'verification_steps' => '完全な再照合（再確認予約）で4指標が一致し、HEALTHY が2回連続することを確認する。',
                'escalation' => 'Trade 担当 → 統計担当。原因未確定のまま補正しない。',
                'prevention_notes' => '集計ジョブの排他制御と遅延到着の扱いを記録する。',
            ],
            'RB-02' => [
                'title' => '会員日別統計の欠落',
                'symptoms' => '完了した元データがある営業日・店舗の会員統計行（ret_app_member_daily_statistics）が存在しない。',
                'impact' => '会員分析の欠落。現在の会員マスターを参照する指標は過去時点を再現できない。',
                'first_checks' => "1. 受信済み店舗の一覧と対象営業日\n2. 統計行の存在\n3. 会員マスター依存指標の時点差",
                'read_only_checks' => '・店舗別の受信完了状況と統計行の有無',
                'decision_steps' => "A. 行欠落 → 集計ジョブ履歴を確認し別作業で補完\nB. 行は存在するが値が疑わしい → 現在会員数を当時値と断定しない",
                'recovery_procedure' => '欠落行の補完は対象店舗・日付を明示してレビュー後に実施。',
                'verification_steps' => '必要店舗行の存在と対象指標を確認。',
                'escalation' => 'Trade 担当',
                'prevention_notes' => '',
            ],
            'RB-03' => [
                'title' => '専用受信リクエストの欠番',
                'symptoms' => '時刻指定の受信リクエスト（tm_receive_requests）が対象時刻に完了していない。',
                'impact' => '他経路（5分受信）で受信できていれば実データの欠落ではない。',
                'first_checks' => "1. 他の5分受信の完了\n2. 対象時刻の元データ有無\n3. 専用リクエストの履歴と last_error_message",
                'read_only_checks' => '・receive_mode / target_hour 別の完了状況',
                'decision_steps' => '専用リクエストの欠番だけでデータ再取込を実行しない。他経路の網羅性を確認する。',
                'recovery_procedure' => '必要なら担当者が受信リクエストを再作成（Trade 画面から）。',
                'verification_steps' => '他経路の網羅性確認、または未解決 WARNING として記録。',
                'escalation' => 'Trade 担当',
                'prevention_notes' => '',
            ],
            'RB-04' => [
                'title' => '送受信リクエストの滞留',
                'symptoms' => 'PENDING / BEFORE_RUNNING が15分超、RUNNING が2時間超。日次送受信の成功履歴がない。',
                'impact' => '店舗同期・POS受信の遅延。',
                'first_checks' => "1. 実プロセス（queue worker / scheduler）の生存\n2. 開始時刻と相手先（FTP接続名）\n3. 最新の成功履歴",
                'read_only_checks' => '・tm_store_sync_requests の execution_status 別件数と scheduled_at',
                'decision_steps' => '生存確認前のロック解除・重複送信を禁止。実行中と停止を区別する。',
                'recovery_procedure' => 'プロセス停止が確認できた場合のみ、担当者が再起動・再送を判断する。',
                'verification_steps' => '成功履歴と必要成果物の確認。',
                'escalation' => 'Trade 担当 → インフラ担当',
                'prevention_notes' => '',
            ],
            'RB-05' => [
                'title' => '帳票メール・外部送信（インテージ／定点／販促）の未完了',
                'symptoms' => '予定時刻＋猶予を過ぎても送信完了履歴がない、または必要な段階が SUCCESS でない。',
                'impact' => '外部提出・帳票配信の遅延。',
                'first_checks' => "1. 対象日と送信先設定（schedule_enabled / paused_at）\n2. 再試行の有無と全段階の状態\n3. error_message",
                'read_only_checks' => '・send_runs の status / started_at / finished_at',
                'decision_steps' => '二重送信の有無を確認してから担当者が対応する。',
                'recovery_procedure' => '担当者が Trade 画面から再送。二重送信に注意。',
                'verification_steps' => '最終段階の成功と配信確認。',
                'escalation' => 'Trade 担当',
                'prevention_notes' => '',
            ],
            'RB-06' => [
                'title' => '商品検索コード最終使用日の未更新',
                'symptoms' => '販売実績があるのに最終使用日が更新されていない（23:00 バッチ）。',
                'impact' => 'マスター更新の問題。統計補正の許可と混同しない。',
                'first_checks' => "1. 有効スケジュールの登録\n2. 入力締切と遅延到着\n3. 別更新経路",
                'read_only_checks' => '・バッチ時点で入力済みの範囲と最終使用日の比較',
                'decision_steps' => 'バッチ後到着データを未実行根拠にしない。',
                'recovery_procedure' => 'スケジュール登録の修正は別ブランチ・別作業。',
                'verification_steps' => '対象確定範囲の値が期待日付以上。',
                'escalation' => 'Trade 担当',
                'prevention_notes' => '',
            ],
            'RB-07' => [
                'title' => 'アプリケーションエラー（ERROR/CRITICAL ログ）',
                'symptoms' => 'サイトのアプリログに ERROR 以上が出力された。',
                'impact' => '例外の種類・発生箇所により異なる。影響ユーザー範囲を特定する。',
                'first_checks' => "1. サイト・例外型・代表スタック\n2. 直前のデプロイ・設定変更\n3. 同一指紋の回数と期間",
                'read_only_checks' => '・該当サイトのログ（伏字済みサンプル）',
                'decision_steps' => '機密情報を除いた再現条件を整理。他案件と安易に統合しない。',
                'recovery_procedure' => '原因に応じた修正。監視からは何も実行しない。',
                'verification_steps' => '原因に対応した再確認結果を「復旧確認」として記録する（ログが静かになっただけでは解決しない）。',
                'escalation' => '各サイト担当',
                'prevention_notes' => '',
            ],
            'RB-08' => [
                'title' => '監視停止（収集器 heartbeat 停止・チェック未完了）',
                'symptoms' => '収集器の heartbeat が5分超、または有効チェックが interval＋grace 内に完了していない。',
                'impact' => '監視の空白。業務の停止とは区別する。',
                'first_checks' => "1. systemd timer / worker の状態\n2. heartbeat・退避ファイル・空き容量\n3. emergency.log",
                'read_only_checks' => '・monitor:health の出力',
                'decision_steps' => '監視の停止と業務の停止を区別。二重起動を避けて再開する。',
                'recovery_procedure' => 'owner ノードで timer を再開。別ノードでの二重起動禁止。',
                'verification_steps' => 'heartbeat 復旧と完全なチェック成功。',
                'escalation' => 'Insights 担当',
                'prevention_notes' => '',
            ],
            'RB-09' => [
                'title' => 'Slack 通知障害',
                'symptoms' => '最古未送信が5分超、または BLOCKED（4xx）。',
                'impact' => 'エラーの見逃し。画面バナーと代替連絡で補う。',
                'first_checks' => "1. HTTP 分類（429 / 4xx / 5xx / timeout）\n2. チャンネル設定（表示名・env 参照名）\n3. 未送信最古時刻",
                'read_only_checks' => '・通知試行履歴（秘密値は表示しない）',
                'decision_steps' => '429 は Retry-After を待つ。4xx は設定を照合。5xx は再試行継続。',
                'recovery_procedure' => '設定修正後に monitor:notify:test で検証チャンネルへ送信。代替連絡先へ連絡。',
                'verification_steps' => '未送信キュー解消と実受信確認。',
                'escalation' => 'Insights 担当 / 運用責任者',
                'prevention_notes' => '',
            ],
            'RB-10' => [
                'title' => '保存・削除遅延・容量逼迫',
                'symptoms' => '退避量が上限に接近、正常ログ削除が24時間以上遅延。',
                'impact' => '監視データの保存不能。未解決エラーは削除対象外。',
                'first_checks' => "1. 退避量と空き容量\n2. 監視テーブル件数の推定\n3. 削除所要時間とDB待ち",
                'read_only_checks' => '・monitor:health の storage 出力',
                'decision_steps' => '正常ログを優先的に整理。未解決エラーを削除しない。',
                'recovery_procedure' => '保持設定・実行予算の見直し。TRUNCATE / 巨大 DELETE は行わない。',
                'verification_steps' => '予算内で処理が追い付き容量が回復。',
                'escalation' => 'Insights 担当',
                'prevention_notes' => '',
            ],
            'RB-11' => [
                'title' => '蔵: 自動発注の未実行・発注計算ジョブの失敗',
                'symptoms' => '自動発注の予定枠（例 01:00）が猶予内に COMPLETED にならない。発注計算ジョブ（ORDER_CALC / SALES_BASED_CALC 等）が FAILED、RUNNING のまま 60 分超、PENDING のまま 30 分超。',
                'impact' => '当日の発注データが生成されず、仕入先への発注（JX 送信）が遅れる。欠品・過剰在庫の原因になる。',
                'first_checks' => "1. 対象 slot_key（YYYY-MM-DD:HH:MM）と有効倉庫数。予定時刻は倉庫設定（wms_warehouse_auto_order_settings.replenishment_config の execution_time / sunday_execution_time / weekdays）で曜日ごとに異なる\n2. 倉庫別の wms_auto_order_runs.status と failed_count / held_count\n3. wms_auto_order_job_controls の最新行（process_name 別）と error_details\n4. 蔵のスケジューラ・キューワーカーの生存",
                'read_only_checks' => "・SELECT warehouse_id, status, generated_count, held_count, failed_count FROM wms_auto_order_runs WHERE slot_key = ? AND is_preview = 0\n・SELECT process_name, status, started_at, finished_at FROM wms_auto_order_job_controls ORDER BY id DESC LIMIT 20",
                'decision_steps' => "A. 実行記録なし → スケジューラ停止・ロック残りを確認\nB. 一部倉庫のみ完了 → 未完了倉庫の設定（発注先・在庫スナップショット）を確認\nC. FAILED → error_details の例外で原因を切り分け（DB 待ち・マスター不整合）\nD. RUNNING 滞留 → 実プロセスが動いているか確認してから判断（二重実行に注意）",
                'recovery_procedure' => '再実行は蔵の画面から倉庫・slot を指定して行う。二重発注を避けるため、生成済みの発注データが無いことを先に確認する。監視画面からは実行しない。',
                'verification_steps' => '対象 slot の全有効倉庫が COMPLETED、計算ジョブの最新が SUCCESS になり、監視が HEALTHY に戻る。',
                'escalation' => '蔵 担当 → 発注業務担当（発注締め時刻に間に合わない場合は手動発注の判断を仰ぐ）',
                'prevention_notes' => '予定枠は監視ルールの slots に設定する。運用で枠を変えたら監視側も合わせる。',
            ],
            'RB-12' => [
                'title' => '蔵: JX 発注データ生成・JX 送受信・発注送信の失敗',
                'symptoms' => 'JX 発注データ生成（月〜土 13:30、日 23:35 など）が予定＋30 分までに SUCCESS にならない。JX 送受信ログの最新が failure。発注送信ログが FAILED、PENDING が 60 分超。',
                'impact' => '仕入先へ発注が届かない・受注確認が取り込めない。締め時刻を過ぎると翌便扱いになる。',
                'first_checks' => "1. wms_jx_order_generation_runs の対象日・時刻の status と error_message\n2. wms_jx_transmission_logs の direction / operation_type / document_type 別の最新と http_code\n3. wms_order_jx_documents の ERROR 件数\n4. JX 接続先（証明書期限・ID/パスワード変更・相手先メンテナンス）",
                'read_only_checks' => "・直近 24 時間の JX 送受信を種別ごとに status 集計\n・HTTP 401/403 は認証、5xx は相手先障害、タイムアウトは回線を疑う",
                'decision_steps' => "A. 生成失敗 → 発注データ（RB-11）の状態を先に確認\nB. 送信 failure・認証エラー → 接続設定の変更有無を確認（監視画面では設定を表示しない）\nC. 相手先 5xx・タイムアウト → 時間を置いた再送で解消するか確認\nD. 手動 CSV 送信の失敗は WARNING。担当者に再送を依頼",
                'recovery_procedure' => '再送は蔵の画面から行う。同一文書の二重送信に注意し、相手先で受信済みでないことを確認する。',
                'verification_steps' => '同じ送受信種別の最新が success になり（指紋に日付を含めないため同じエラーが復旧する）、ERROR 文書が 0 件になる。',
                'escalation' => '蔵 担当 → JX 相手先窓口（相手先障害の場合）',
                'prevention_notes' => '有効な JX 設定があるのに 48 時間送受信が無い場合も警告する。休止する場合は機能 wms.jx を無効化する。',
            ],
            'RB-13' => [
                'title' => '蔵: EOS 入荷予定受信の失敗・入荷取込エラーの急増',
                'symptoms' => '有効な受信スケジュール（DAILY 22:00、月曜 10:00/16:00 など）の時刻＋猶予までに SUCCEEDED が無い、PARTIAL_FAILED、または入荷取込エラーが平常（7 日中央値）の 2 倍超。',
                'impact' => '入荷予定が取り込まれず、入荷検品・在庫計上が遅れる。',
                'first_checks' => "1. wms_eos_incoming_receive_runs の execution_date / scheduled_time / status / error_summary\n2. スケジュールの is_enabled（無効スケジュールは監視対象外）\n3. wms_incoming_import_errors の error_type 別件数（前日）",
                'read_only_checks' => '・受信ファイルの到着有無（相手先の送信遅延か、取込側の失敗か）',
                'decision_steps' => "A. 実行記録なし → スケジューラ停止を疑う\nB. 一部失敗 → error_summary の内容（商品未登録・価格不一致）で業務担当へ連絡\nC. 取込エラー急増 → マスター変更・相手先フォーマット変更を確認",
                'recovery_procedure' => '再受信は蔵の画面から実行する。マスター不備は業務担当が修正してから再取込する。',
                'verification_steps' => '対象スケジュールが SUCCEEDED、取込エラー件数が平常に戻る。',
                'escalation' => '蔵 担当 → 入荷業務担当',
                'prevention_notes' => '平常件数の多い顧客は監視ルールの min_count を調整する。',
            ],
            'RB-14' => [
                'title' => '勘定衆: 統計の定時集計・日次確定・販売実績集計の失敗',
                'symptoms' => '統計の定時集計（02:00, 07:00, 08:10, 09:00, 12:00, 16:00, 19:00 など）が猶予内に succeeded にならない、failed がある、日次確定（daily_finalize と部門同期）が 02:45 までに完了しない、販売実績集計が failed または進捗が止まっている。',
                'impact' => '売上・粗利などの統計画面と帳票が古いまま、または欠落する。伝票・請求には影響しない。',
                'first_checks' => "1. stats_import_runs の job_type / source_system / status / error_message（対象枠の前後）\n2. 部門同期（daily_department_sync）の有無\n3. stats_sales_performance_runs の progress_stage と progress_updated_at\n4. 勘定衆のスケジューラ・キューワーカー",
                'read_only_checks' => '・同時刻に締め処理や大量取込が走っていないか（DB 待ち）',
                'decision_steps' => "A. 実行記録なし → スケジューラ停止\nB. failed → error_message で原因を切り分け\nC. 部門同期のみ欠落 → 部門マスター側の問題を確認\nD. 販売実績集計の滞留 → ワーカーの再起動前に実行中プロセスを確認",
                'recovery_procedure' => '再集計は勘定衆の画面・コマンドで対象期間を指定して実施する。決算確定済みの期間は対象にしない。',
                'verification_steps' => '次の枠（または再実行）で succeeded になり、監視が HEALTHY に戻る。',
                'escalation' => '勘定衆 担当 → 統計担当',
                'prevention_notes' => '集計枠は監視ルールの slots と一致させる。',
            ],
            'RB-15' => [
                'title' => '勘定衆: 締め処理の失敗',
                'symptoms' => '仕入締め（supplier_bill）・得意先締め（buyer_bill）の最新実行が failed、または error_count > 0。',
                'impact' => '請求書・支払明細が作成されない、または一部欠落する。月次業務に直接影響する。',
                'first_checks' => "1. closing_processes の process_type / closing_date / status / error_count / total_count / error_message\n2. 同じ締め日の再実行有無（最新行で判定）",
                'read_only_checks' => '・エラー対象の取引先と伝票の状態（未確定伝票・マスター不備）',
                'decision_steps' => "A. 一部取引先のみエラー → 該当取引先のマスター・伝票を確認\nB. 全件失敗 → 実行時の例外・DB 待ちを確認",
                'recovery_procedure' => '締め処理の再実行は勘定衆の画面から業務担当が行う。監視画面からは実行しない。',
                'verification_steps' => '同じ締め日の最新が completed になり、監視が HEALTHY に戻る。',
                'escalation' => '勘定衆 担当 → 経理担当',
                'prevention_notes' => '',
            ],
            'RB-16' => [
                'title' => '千里眼: 検索データ更新の停止',
                'symptoms' => '全件更新（毎日 03:00 頃）が 04:00 までに成功しない、在庫差分更新（15 分おき）の最終成功が 45 分（警告）/ 90 分（エラー）以上前、状態が error。',
                'impact' => '商品検索の結果・在庫表示が古くなる。発注・受注画面の検索に影響する。',
                'first_checks' => "1. srh_search_sync_states の name / status / last_success_at / last_error\n2. 検索エンジン（OpenSearch 等）の稼働と容量\n3. 千里眼のスケジューラ・キューワーカー",
                'read_only_checks' => '・last_checked_item_count / last_updated_item_count の推移',
                'decision_steps' => "A. last_error が接続エラー → 検索エンジン側を確認\nB. 実行されていない → スケジューラ停止・ロック残り\nC. 件数が急減 → 元データ側の変更を確認",
                'recovery_procedure' => '全件更新の再実行は千里眼のコマンドで行う（負荷が高いため業務時間外を推奨）。',
                'verification_steps' => 'last_success_at が更新され、監視が HEALTHY に戻る。',
                'escalation' => '千里眼 担当',
                'prevention_notes' => '',
            ],
            'RB-17' => [
                'title' => '飛脚: 配送端末の同期・集金・容器回収データの異常',
                'symptoms' => '運行が FAILED_TO_SYNC、IN_PROGRESS_SYNC が 30 分超、READY/WAIT が 60 分超、前日の運行が翌 06:00 に未完了。集金の不正検知・48 時間未確認。勘定衆への集金（DEPOSIT）・容器回収（EMPTY_CONTAINER_PICKUP）連携が 02:30 までに無い、またはエラー。',
                'impact' => '配送実績・集金額・容器回収数が勘定衆に反映されず、入金消込・容器管理が遅れる。',
                'first_checks' => "1. dlv_operations の status / user_id / app_version / updated_at\n2. 端末側の通信状況（ドライバーへ確認）とアプリバージョン\n3. 勘定衆の external_collaboration_data（partner = MOBILE_COLLECT）の status と failed_count\n4. 集金の cash_fraud_detection_status と管理者確認状況",
                'read_only_checks' => '・前日 FINISHED 運行数と連携行数の比較\n・容器回収データ（dlv_empty_container_collects）の有無',
                'decision_steps' => "A. 端末同期失敗 → 端末の再送操作を依頼（アプリ更新が必要な場合あり）\nB. 連携なし → 飛脚の送信バッチ（02:00）の実行有無\nC. 連携エラー → 勘定衆側の取込エラー内容（RB-18 と同じ手順）\nD. 不正検知 → 業務担当が内容を確認し、管理者承認する（監視では判断しない）",
                'recovery_procedure' => '再送は飛脚の画面（配送実績）から行う。勘定衆側の取込は勘定衆の画面で再適用する。',
                'verification_steps' => '対象運行が FINISHED、連携行が APPLYING_SUCCESS になり、監視が HEALTHY に戻る。',
                'escalation' => '飛脚 担当 → 配送業務担当（集金の不一致は経理担当）',
                'prevention_notes' => '配送件数の少ない顧客は監視ルールの min_ops_for_deposit / min_deliveries を調整する。',
            ],
            'RB-18' => [
                'title' => '勘定衆: 外部連携取込の失敗',
                'symptoms' => '外部連携（EARNING / DEPOSIT / PURCHASE / PAYMENT 等）の最新が VALIDATION_ERROR / APPLY_ERROR、failed_count > 0、APPLY_WAIT が 2 時間超。',
                'impact' => '連携先からの売上・入金・仕入データが取り込まれない、または一部欠落する。',
                'first_checks' => "1. external_collaboration_data の edi_type / partner / status / total_lines / failed_count / debug_message\n2. 明細テーブルの error_* フラグ（得意先・商品の未登録など）\n3. 連携ファイル・API の到着時刻",
                'read_only_checks' => '・同じパートナーの直近成功時刻',
                'decision_steps' => "A. VALIDATION_ERROR → ファイル形式・必須項目の不備（相手先へ確認）\nB. APPLY_ERROR → マスター不備を業務担当が修正\nC. APPLY_WAIT 滞留 → 取込ワーカーの停止",
                'recovery_procedure' => '再適用は勘定衆の外部連携画面から行う。',
                'verification_steps' => '同じ連携種別の最新が APPLYING_SUCCESS になり、監視が HEALTHY に戻る。',
                'escalation' => '勘定衆 担当 → 連携先窓口',
                'prevention_notes' => '',
            ],
            'RB-19' => [
                'title' => 'サーバー資源の逼迫・プロセス停止（CPU・メモリ・ディスク・Apache・PHP-FPM・supervisord）',
                'symptoms' => 'ノードの CPU 使用率・iowait・load・メモリ available・ディスク使用率がしきい値を継続して超えている。Apache・PHP-FPM・supervisord が 5 分以上確認できない（重大。確認は 1 分ごと）。資源収集が止まっている。',
                'impact' => '同じサーバー上の全サービス（複数顧客を含む）の応答遅延・停止。ディスク満杯はログ・DB 書き込み不能。',
                'first_checks' => "0. プロセス停止の場合: 該当サーバーで systemctl status httpd（apache2）/ php-fpm / supervisord と journalctl -u を確認。ELB の振り分けからは外れていても、残りのサーバーに負荷が集中する\n1. ノード詳細のグラフで開始時刻と継続時間を確認（1 秒バーストがあればその区間）\n2. 同時刻のエラー・監視ログ（バッチ・取込の集中）\n3. top / ps で CPU・メモリを使っているプロセス\n4. df -h / df -i、du で増えたディレクトリ（ログ・一時ファイル）",
                'read_only_checks' => "・sar -u -r -d（sysstat、10 秒・60 日）で前後比較\n・atop -r（60 秒・30 日）でプロセス別\n・/proc/pressure の some/full",
                'decision_steps' => "P. プロセス停止 → OOM Killer（dmesg）・設定変更・ディスク満杯を確認してから再起動。supervisord 停止中はキューワーカーも止まっている\nA. 特定バッチの時間帯だけ → バッチの分散・時間変更を検討\nB. メモリ増加が続く → ワーカーのリーク（定期再起動の設定）\nC. ディスク増加 → ログのローテーション・古い一時ファイルの整理\nD. iowait 高・steal 高 → ストレージ・インスタンス種別を確認",
                'recovery_procedure' => 'ログ・一時ファイルの整理は対象を確認してから行う。DB データやアップロードファイルを削除しない。プロセスの停止は影響範囲を確認してから担当者が行う。',
                'verification_steps' => '指標がしきい値を下回り、連続して正常になって監視が RECOVERED になる。',
                'escalation' => 'インフラ担当',
                'prevention_notes' => 'しきい値はノード別に監視ルールで調整できる。',
            ],
            'RB-20' => [
                'title' => 'サービスの応答異常（HTTP プローブ）',
                'symptoms' => 'ヘルスチェック URL（/up）が 2xx 以外・タイムアウト（5 秒）を連続して返す。応答時間が増えている。プローブ自体が止まっている。',
                'impact' => '利用者が画面を開けない、または非常に遅い。',
                'first_checks' => "1. 同じノードの資源（RB-19）\n2. Web サーバー（nginx）・PHP-FPM のエラーログとプロセス数\n3. 直前のデプロイ有無\n4. 証明書期限（HTTPS エラーの場合）",
                'read_only_checks' => '・プローブ結果の http_status と error_code の推移\n・他サービス（同じノード）も異常か',
                'decision_steps' => "A. 同じノードの全サービスが異常 → サーバー・nginx を疑う\nB. 1 サービスのみ → そのアプリのログ・デプロイ\nC. 5xx → アプリ例外、タイムアウト → PHP-FPM の枯渇・DB 待ち",
                'recovery_procedure' => '再起動・ロールバックは担当者が影響を確認して行う。監視画面からは実行しない。',
                'verification_steps' => 'プローブが連続して 2xx になり、監視が RECOVERED になる。',
                'escalation' => '該当サービス担当 → インフラ担当',
                'prevention_notes' => '/up は DB・セッションに触れない軽量ルートにする。',
            ],
            'RB-21' => [
                'title' => '勘定衆のスケジューラ（cron）停止',
                'symptoms' => 'ノードの必須プロセス cron が 5 分以上いない（node.resources の NODE_PROCESS_DOWN）。または予定枠の統計処理が起動しない（STATS_JOB_NOT_STARTED）が続く。',
                'impact' => 'そのノードの定時処理（統計の再集計・前日確定・部門同期・月次・締め）が起動しない。全ノード停止なら統計が更新されず、翌朝の帳票・画面の数字が古くなる。',
                'first_checks' => "1. 該当ノードで `systemctl status cron` と `crontab -l -u www-data`（schedule:run の行があるか）\n2. `php artisan schedule:run` を手で 1 回実行してエラーが出ないか（.env・権限・ディスク）\n3. 直前のデプロイ・サーバー再起動・時刻ずれ（chrony）\n4. DB 接続（heartbeat は DB に書く。DB 障害なら他の案件も同時に出る）",
                'read_only_checks' => '・監視ログで NODE_PROCESS_DOWN（cron）の開始時刻\n・同じノードの資源（RB-19）と Apache / PHP の生存\n・stats_import_runs の直近の started_at（どの枠まで動いたか）',
                'decision_steps' => "A. 1 ノードだけ停止・他ノードは稼働 → onOneServer の処理はもう一方が実行するので業務影響は限定的。該当ノードの cron を復旧\nB. 全ノード停止 → 直近の予定枠（02:00 / 07:00 …）が飛んでいないか core.stats_job_slot を確認。飛んだ枠は復旧後に担当者が手動実行の要否を判断\nC. DB 接続失敗が原因 → DB 側の案件を優先",
                'recovery_procedure' => 'cron の再起動・crontab の修正は担当者が行う。監視画面からは実行しない。飛んだ予定枠の再実行はこの手順書では行わず RB-22 に従う。',
                'verification_steps' => 'cron プロセスが復帰し、次の予定枠が起動する（core.stats_job_slot が正常）。',
                'escalation' => 'インフラ担当 → 勘定衆担当',
                'prevention_notes' => 'cron 行は構成管理に含め、デプロイで消えないようにする。',
            ],
            'RB-22' => [
                'title' => '統計の定時処理が未起動・未完了・一部未集計',
                'symptoms' => 'STATS_JOB_NOT_STARTED（予定枠に子記録なし・更新窓に伝票更新あり）/ STATS_JOB_OVERRUN（期限を過ぎても running）/ STATS_JOB_FAILED / STATS_DATE_SUSPECT_MISSING・STATS_DEPT_SYNC_SUSPECT_MISSING（更新のあった計上日が未集計＝抽出漏れ疑い）/ STATS_FINALIZE_MISSING（前日確定が未完了）。',
                'impact' => '顧客別・部門別の売上統計が古い、または一部の日付だけ欠けた状態で帳票・画面に出る。前日確定が無いと日次帳票の数字が確定しない。',
                'first_checks' => "1. cron の生存（RB-21）。全ノード停止なら先にそちら\n2. stats_import_runs の該当枠（started_at が枠の時刻）: どの対象日が処理されたか・error_message\n3. 未完了の対象日について stats_import_runs（daily_aggregate / daily_department_sync）の status と error_message\n4. running が長い場合: `SHOW PROCESSLIST` でロック待ち（GET_LOCK）・長時間クエリ。前の枠の処理が終わっていない（withoutOverlapping）",
                'read_only_checks' => '・該当日の売上伝票（earnings）の更新件数が極端に多くないか（再集計が長引く原因）\n・同じ日付の過去の実行時間（通常数秒〜数十秒／日）',
                'decision_steps' => "A. 起動なし＋cron 正常 → 前の枠が running（重なり見送り）か、デプロイ中。前の枠の完了を待つ。次の枠で対象日が再び対象になれば自然に解消する\nB. 失敗 → error_message の原因（デッドロック・外部接続・データ不整合）。原因除去後に担当者が `stats:refresh-all-updated-daily-buyer-trade-profit --previous_boundary=N` で再実行\nC. 一部の対象日だけ未集計 → その日だけ `stats:create-daily-buyer-trade-profit --stats_date=YYYY-MM-DD --sync` で再集計（部門同期は --sync-departments）\nD. 前日確定が未完了 → 再集計の失敗が原因なら B を先に。確定だけ残っていれば `stats:finalize-daily-buyer-trade-profit --stats_date=前日`",
                'recovery_procedure' => '再実行はすべて担当者が勘定衆のサーバーで行う。監視からは実行しない。再実行は対象日を指定し、同じ日付の成功で監視が復旧することを確認する（別の日付の成功では解消しない）。',
                'verification_steps' => '該当の対象日・予定枠の案件が RECOVERED になる。core.stats_daily_finalize が前日分で正常。',
                'escalation' => '勘定衆担当 → DB 担当（ロック・長時間クエリの場合）',
                'prevention_notes' => '予定枠や --previous_boundary を変えたら監視ルール（core.stats_job_slot の slots / 更新窓）も更新する。',
            ],
            'RB-23' => [
                'title' => '脆弱性報告の確認と対応',
                'symptoms' => 'ADVISORY_UNREVIEWED（未確認の脆弱性情報に「重大」「悪用確認（CISA KEV）」「導入版が影響範囲」がある）/ ADVISORY_FETCH_STALE・ADVISORY_FETCH_NEVER（情報源からの取得が止まっている）。',
                'impact' => '既知の脆弱性を抱えたまま運用が続く。悪用確認済み（KEV）は攻撃が実際に観測されているもので、放置の危険が高い。',
                'first_checks' => "1. 脆弱性情報の画面（監視 → 脆弱性情報）で「新規」タブを開き、重大・悪用確認・導入版が影響 のバッジが付いた行から順に内容を読む
2. 対象製品（Apache / PHP / Laravel / Livewire / Ubuntu / OpenSSL / MySQL / AWS）と、本番の導入版（影響パッケージの「導入」列）を見比べる
3. 取得停止の場合: owner ノードで `php artisan monitor:fetch-advisories` を手動実行し、FAIL の情報源の理由（HTTP 4xx/5xx・名前解決・プロキシ）を確認",
                'read_only_checks' => '・ins_monitor_advisory_fetches の直近行（情報源ごとの OK / FAILED と error）
・本番の `php -v`、`apache2 -v`、`composer show laravel/framework livewire/livewire`、`lsb_release -a`',
                'decision_steps' => "A. 導入していない製品・版 → 「対象外」にして理由（例: 該当版を使っていない）を残す
B. 導入版が影響範囲・修正版あり → 修正版への更新を計画し「確認済み」に。更新完了後に「対応済み」
C. 修正版なし（回避策のみ）→ 回避策の適用可否を判断し「確認済み」。定期的に再確認
D. KEV 掲載 → 最優先。暫定対処（WAF・設定変更・機能停止）を先に検討",
                'recovery_procedure' => 'OS・ミドルウェア・ライブラリの更新は担当者が手順書に従って実施する（監視画面からは実行しない）。更新後は再デプロイと動作確認を行い、該当行を「対応済み」にする。',
                'verification_steps' => '未確認の「重大・悪用確認・導入版が影響」が 0 件になり monitor.advisories が正常。取得停止の場合は最終取得が更新される。',
                'escalation' => 'インフラ担当 → 開発責任者（アプリ層の脆弱性）',
                'prevention_notes' => '取得は 6 時間ごと（sakemaru-monitor-advisories@.timer）。API キー（MONITOR_NVD_API_KEY / MONITOR_GITHUB_TOKEN）を設定すると取得の制限が緩和される。情報源はこれだけで網羅ではない（docs/monitoring/security-advisories.md）。',
            ],
        ];
    }

    public function sync(): void
    {
        $now = CarbonImmutable::now('UTC');
        foreach (self::definitions() as $key => $def) {
            Runbook::query()->updateOrCreate(
                ['runbook_key' => $key, 'version' => self::VERSION],
                array_merge($def, ['enabled' => true, 'reviewed_at' => $now, 'owner_group' => 'ops']),
            );
        }
    }

    public function latest(string $key): ?Runbook
    {
        return Runbook::query()->where('runbook_key', $key)->where('enabled', true)->orderByDesc('version')->first();
    }
}
