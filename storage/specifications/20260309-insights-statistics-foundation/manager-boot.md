# Manager: insights-statistics-foundation

- **ID**: insights-statistics-foundation-manager
- **作成日**: 2026-03-09
- **最終更新**: 2026-03-09
- **ステータス**: 進行中
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-insights/storage/specifications/20260309-insights-statistics-foundation/

## 役割

このファイルは **管理者（Manager）** 用の進捗管理ファイルである。
管理者は以下を行う:

1. 3チームの依存関係に基づき、実行順序を制御する
2. 各チームの boot.md を読み、進捗を確認する
3. 依存関係が解消されたチームを worktree エージェントとして並列起動する
4. 全チーム完了後に統合検証を行う

## セッション再開手順

1. このファイルを読む（manager-boot.md）
2. 下記「チーム進捗」テーブルで現在の状態を確認
3. 各チームの boot.md を読み、詳細な進捗を確認
4. 依存関係テーブルに基づき、次に起動すべきチームを判断
5. manager-prompt.md の未完了行を確認し、次のアクションを実行

## 概要

sakemaru-insights 小売統計基盤を3チームで並列構築する。

## チーム構成

| チーム | ディレクトリ | 担当範囲 | boot.md |
|--------|-------------|---------|---------|
| DB設計 | `db-design/` | P0: DB調査 → P1: マイグレーション → P2: モデル | `db-design/db-design-boot.md` |
| Python ETL | `python-etl/` | P3: ETL基盤 → P4: Laravel実行フロー | `python-etl/python-etl-boot.md` |
| UI | `ui/` | P5: Filament UI（6帳票） | `ui/ui-boot.md` |

## 依存関係

```text
DB設計 P0 (DB調査)
  ↓
DB設計 P1 (マイグレーション) ──→ Python ETL P3 (ETL基盤) 開始可能
  ↓
DB設計 P2 (モデル) ──→ UI P5 (Filament UI) 開始可能
                  ──→ Python ETL P4 (Laravel実行フロー) 開始可能
```

| チーム | 開始条件 | ブロッカー |
|--------|---------|-----------|
| DB設計 | なし（最初に開始） | - |
| Python ETL | DB設計 P1（マイグレーション）完了 | テーブルが存在しないとETLが動かない |
| UI | DB設計 P2（モデル）完了 | モデルがないとFilament Resourceが作れない |

## 実行フロー

### Phase 1: DB設計チーム単独実行
- DB設計チームを起動（P0 → P1 → P2）
- P0 完了時: ret_テーブル構造を「共有コンテキスト」に記録
- P1 完了時: Python ETL チームを起動可能

### Phase 2: 並列実行（DB設計 P2 + Python ETL P3）
- DB設計チームが P2（モデル）を実行中に、Python ETL チームが P3（ETL基盤）を開始
- P2 完了時: UI チームを起動可能

### Phase 3: 並列実行（Python ETL P4 + UI P5）
- Python ETL P4 と UI P5 を並列実行

### Phase 4: 統合検証
- 全チーム完了後に統合検証を実施

---

## チーム進捗

| チーム | 状態 | 更新日 | 現在Phase | 備考 |
|--------|------|--------|----------|------|
| DB設計 | 完了 | 2026-03-09 | P0→P1→P2 全完了 | 15テーブル+15モデル |
| Python ETL | 完了 | 2026-03-09 | P3+P4 完了 | ETL実行済。検算PASS: ret_=ins_=26,652,463。全15テーブルにデータ投入済 |
| UI | 完了 | 2026-03-09 | P5: 全6帳票完了 | 6ページ全ルート登録済。Filament v4型修正済。mega-menu復元済 |

---

## 共有コンテキスト

> 全チームが参照する共有情報。DB設計チームの P0 完了時に記入。

### ret_テーブル構造（DB設計 P0 完了 2026-03-09）
- ret_daily_sales: 日付=`slip_date`(NOT business_date), item_code(varchar20), category_code(varchar20), sales_qty/amount(int), gross_profit(int), cost_amount(decimal11,2), shipping_ret_store_id/sales_ret_store_id 確認済み。13.8M行
- ret_hourly_sales: daily_sales と同構造 + time_slot(varchar10)
- ret_daily_settlement_summary: `slip_date`, **レジ単位**(ret_store_register_id) → 店舗単位は SUM GROUP BY 必要。customer_count(int)
- ret_stores: **存在する** code(char10), name(varchar100)。**area/region カラムなし** → dim_store で独自管理
- ret_items: **存在しない**。共有 `items` テーブル（ret_なし）を使用。code(int), name(varchar)
- item_categories: 共有テーブル（ret_なし）。code(int), name(varchar), parent_id, depth
- ret_item_categories: ブリッジテーブル（item_category_id + 税設定のみ）
- 精算系テーブル: 8テーブル全確認済み。全て ret_store_id + business_date + ret_store_register_id 単位
- sales_ret_store_id / shipping_ret_store_id: **確認済み**（both nullable bigint）

### マイグレーション完了情報（DB設計 P1 完了 2026-03-09）
- テーブル一覧: dim_store, dim_item, dim_category, dim_date, dim_time_slot, sales_fact, hourly_sales_fact, daily_store_sales, daily_item_sales, daily_category_sales, daily_store_item_sales, hourly_store_sales, daily_payment_summary, monthly_store_sales, monthly_item_sales
- 重要: ret_daily_sales の slip_date → ins_ では business_date にマッピング
- 重要: ret_stores に area/region なし → dim_store.area は nullable（手動管理）
- 重要: 商品/カテゴリ名は共有テーブル items / item_categories から取得

### モデル完了情報（DB設計 P2 完了 2026-03-09）
- モデル一覧: SalesFact, HourlySalesFact, DimStore, DimItem, DimCategory, DimDate, DimTimeSlot, DailyStoreSales, DailyItemSales, DailyCategorySales, DailyStoreItemSales, HourlyStoreSales, DailyPaymentSummary, MonthlyStoreSales, MonthlyItemSales
- namespace: App\Models\Insights

### Git ブランチ
- ベースブランチ: release/v1.0
- DB設計ブランチ: feature/insights-db-design
- Python ETLブランチ: feature/insights-python-etl
- UIブランチ: feature/insights-ui

---

## 重要な設計制約（全チーム共通）

- **DB_TABLE_PREFIX='ins_'**: マイグレーションのテーブル名に `ins_` を含めない
- **FK禁止**: ins_ → ret_ の外部キー禁止
- **ret_直接参照禁止**: sakemaru-insights は ins_ のみ参照
- **Summary層フラット構造**: store_name, area, item_name 等を冗長保持、JOINなし
- **クエリ50ms以内**: 画面は Summary を直接読む、Fact の都度集計は避ける
- **粒度統一**: 売上基準は ret_daily_sales に固定、settlement系は支払分析のみ
- **UPSERT冪等**: INSERT ... ON DUPLICATE KEY UPDATE
- **破壊的操作禁止**: DROP/TRUNCATE 禁止
- **在庫金額レポートは対象外**

---

## 絶対禁止事項（全チーム共通）

1. **`php artisan migrate:refresh` / `migrate:fresh` は絶対に実行禁止** — 既存データが全て消える
2. 既存の ret_ テーブル・他システムのテーブルを破壊する操作は一切禁止
3. DB全体に影響する操作（DROP DATABASE 等）は禁止

---

## テスト環境情報（全チーム共通）

### ローカルDB
- DB: `sakemaru_hana_prod`
- User: `root`
- Password: なし（空）
- Host: `127.0.0.1:3306`

### ローカルURL
- URL: `https://insights.sakemaru.test/admin`
- ログインユーザー: `admin@sakemaru.ai`
- パスワード: `1234567a`

### 既存データ
- 統計データはすでにローカルDBに入っている（ret_テーブル群にrawデータが存在）
- ins_ テーブルは新規作成だが、ret_ のデータは保護すること

### テスト時のルール
1. **再テスト時はテーブル単位の TRUNCATE のみ許可**（ins_ テーブルのみ。ret_ は絶対に TRUNCATE しない）
2. データが正しく保存されているかを確認する
3. 各ページに正しくアクセスできるかを確認する
4. キャッシュ削除: `php artisan cache:hard-clear`
5. メニュー構成は `mega-menu.blade.php` を参考にする（git commit `4247f69` に存在、現在のブランチには未反映）

### mega-menu 参考情報
- Livewire コンポーネント: `app/Livewire/MegaMenu.php`
- Blade テンプレート: `resources/views/livewire/mega-menu.blade.php`
- レイアウト: `resources/views/vendor/filament-panels/components/layout/index.blade.php`
- commit `4247f69` から cherry-pick またはファイルを復元して使用する
- スタイル: slate-800 背景、indigo-600 アクセント、Alpine.js ドロップダウン

---

## 統合検証チェックリスト（全チーム完了後）

### DB検証
- [x] `php artisan migrate` が成功し全15 ins_ テーブルが存在（SHOW TABLES確認済）
- [x] 既存テーブル（ret_* 等）が影響を受けていないことを確認（ret_daily_sales: 13,847,009行）
- [x] 全15モデルの `getTable()` が正しい（tinkerで全モデル確認済）

### ETL検証
- [x] `python scripts/etl/generate_retail_stats.py --mode=daily` が正常完了（2026-02-28で実行）
- [x] `php artisan insights:generate-stats --help` コマンド登録確認済
- [x] 検算: ret_daily_sales 売上合計(26,652,463) = ins_daily_store_sales(26,652,463) ✓
- [x] 検算: daily_store_sales = daily_item_sales = sales_fact = 26,652,463 ✓（category_salesは376差=NULLカテゴリ除外で正常）
- [x] Scheduler に realtime(15分毎)/daily(02:00) が登録されている

### UI検証
- [x] `https://insights.sakemaru.test/admin` にログインできる（HTTP 200）
- [x] 6帳票のルートが全てFilamentに登録（route:list確認済）
- [ ] 各帳票ページにアクセスしてデータが正しく表示される（ブラウザ確認必要）
- [ ] フィルタ・ソートが動作する（ブラウザ確認必要）
- [x] mega-menu ファイル復元済（commit 4247f69からMegaMenu.php + blade + layout復元）

### 再テスト手順
1. `php artisan cache:hard-clear`
2. ins_ テーブルのみ TRUNCATE（必要な場合）
3. ETL 再実行
4. ブラウザで各ページを確認
