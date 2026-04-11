# Work Plan: Python ETLチーム

- **ID**: insights-python-etl
- **作成日**: 2026-03-09
- **最終更新**: 2026-03-09
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-insights/storage/specifications/20260309-insights-statistics-foundation/python-etl/
- **管理者ファイル**: ../manager-boot.md

## 開始条件

**DB設計チームの P1（マイグレーション）が完了していること。**
開始前に以下を確認:
1. ../manager-boot.md の「共有コンテキスト > マイグレーション完了情報」が記入済み
2. `SHOW TABLES LIKE 'ins_%'` で全15テーブルが存在
3. ../manager-boot.md の「共有コンテキスト > ret_テーブル構造」を参照してカラム情報を取得

## セッション再開手順

1. このファイルを読む（python-etl-boot.md）
2. python-etl-plan.md を読む
3. 下記「進捗」テーブルで現在のPhaseを確認
4. ../manager-boot.md の「共有コンテキスト」から必要情報を取得
5. 未完了の最初のPhaseから作業再開

## 概要

ret_テーブルから ins_テーブルへデータを変換・集計する Python ETL 基盤を構築し、Laravel Artisan コマンド + Scheduler で自動実行する。

## 絶対禁止事項

- **`php artisan migrate:refresh` / `migrate:fresh` は絶対に実行禁止**
- 再テスト時は ins_ テーブル単位の TRUNCATE のみ許可（ret_ は絶対に TRUNCATE しない）
- キャッシュ削除: `php artisan cache:hard-clear`

## テスト環境

- DB: `sakemaru_hana_prod` / User: `root` / Password: なし / Host: `127.0.0.1:3306`
- URL: `https://insights.sakemaru.test/admin`

## 重要な設計制約

- **粒度統一**: 売上・数量・粗利の基準ソースは ret_daily_sales に固定
- **settlement系は支払分析のみ**: 売上基準値として使わない
- **UPSERT冪等**: INSERT ... ON DUPLICATE KEY UPDATE（破壊的操作禁止）
- **検算必須**: ret_ 合計と ins_ 合計の一致確認

## 対象ファイル

### 新規作成
- `scripts/etl/generate_retail_stats.py`
- `scripts/etl/requirements.txt`
- `scripts/etl/config.py`
- `scripts/etl/etl/__init__.py`
- `scripts/etl/etl/extract.py`
- `scripts/etl/etl/transform.py`
- `scripts/etl/etl/load.py`
- `app/Console/Commands/GenerateStatsCommand.php`

### 既存変更
- `routes/console.php`（Scheduler登録）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P3: Python ETL基盤 | 完了 | 2026-03-09 | 実行済。検算PASS: ret_=ins_=26,652,463 |
| P4: Laravel実行フロー | 完了 | 2026-03-09 | Artisanコマンド + Scheduler 登録済（15分毎realtime + 毎日02:00 daily） |

---

## 作業中コンテキスト

### ETL実行結果（P3完了 2026-03-09）
- 処理レコード数: 89,151行（sales_fact:9,258 + hourly_fact:10,810 + daily各種 + monthly + dim）
- 検算結果: PASS — ret_daily_sales(26,652,463) = ins_daily_store_sales(26,652,463) = ins_daily_item_sales(26,652,463)
- daily_category_salesは376差（NULL categoryの商品が除外、想定通り）
- エラー有無: dim_time_slotのtimestampsエラーを修正済（has_timestamps=False）

### 実行手順（sandbox制限のため手動実行が必要）
1. `pip3 install -r scripts/etl/requirements.txt`
2. `python3 scripts/etl/generate_retail_stats.py --mode=daily --date=2026-02-28`
3. 検算結果を確認（ret_daily_sales vs ins_daily_store_sales の sales_amount 一致）
4. `php artisan insights:generate-stats --mode=daily --date=2026-02-28`（vendor必要）

### Git ブランチ
- 作業ブランチ: feature/insights-python-etl
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P3: Python ETL基盤
- 完了日: 2026-03-09（コード完了）
- 成果物: scripts/etl/
- 実績:
  - scripts/etl/generate_retail_stats.py（メインETL: daily/realtime/full 3モード対応）
  - scripts/etl/config.py（.envから接続設定読み込み）
  - scripts/etl/etl/extract.py（ret_daily_sales, ret_hourly_sales, settlement, stores, items, categories）
  - scripts/etl/etl/transform.py（sales_fact, hourly_fact, 6 daily summary, 2 monthly summary, 5 dimension）
  - scripts/etl/etl/load.py（INSERT ON DUPLICATE KEY UPDATE による冪等UPSERT、バッチ1000件）
  - 検算ロジック実装済み（ret_ vs ins_ の sales_amount/qty/profit 一致確認 + クロスレポート整合性）
  - 30日チャンク処理によるメモリ管理（fullモード）

### P4: Laravel実行フロー
- 完了日: 2026-03-09（コード完了）
- 成果物: app/Console/Commands/GenerateStatsCommand.php, routes/console.php
- 実績:
  - GenerateStatsCommand: insights:generate-stats {--mode=daily} {--date=} {--start=} {--end=} {--skip-verify}
  - Process::run() でPythonスクリプト呼び出し、終了コードで成功/失敗判定、ログ記録
  - Scheduler: 15分毎 realtime + 毎日02:00 daily（withoutOverlapping）
- **完了時アクション**: manager-boot.md のチーム進捗を更新
