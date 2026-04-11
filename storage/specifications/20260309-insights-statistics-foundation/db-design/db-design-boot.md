# Work Plan: DB設計チーム

- **ID**: insights-db-design
- **作成日**: 2026-03-09
- **最終更新**: 2026-03-09
- **ステータス**: 進行中
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-insights/storage/specifications/20260309-insights-statistics-foundation/db-design/
- **管理者ファイル**: ../manager-boot.md

## セッション再開手順

1. このファイルを読む（db-design-boot.md）
2. db-design-plan.md を読む
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」で完了済みの実績を確認
5. 未完了の最初のPhaseから作業再開
6. **P0完了時・P1完了時・P2完了時は必ず ../manager-boot.md の「共有コンテキスト」を更新すること**

## 概要

ret_テーブルの実構造調査 → ins_統計テーブル群のマイグレーション作成 → Eloquentモデル作成を担当する。

## 絶対禁止事項

- **`php artisan migrate:refresh` / `migrate:fresh` は絶対に実行禁止**
- 再テスト時は ins_ テーブル単位の TRUNCATE のみ許可（ret_ は絶対に TRUNCATE しない）
- キャッシュ削除: `php artisan cache:hard-clear`

## テスト環境

- DB: `sakemaru_hana_prod` / User: `root` / Password: なし / Host: `127.0.0.1:3306`

## 重要な設計制約

- **DB_TABLE_PREFIX='ins_'**: マイグレーションのテーブル名に `ins_` を含めない（自動付与）
- **FK禁止**: ins_ → ret_ の外部キー禁止
- **Summary層フラット構造**: store_name, area, item_name, category_name, payment_label を冗長保持
- **粒度統一**: 売上基準は ret_daily_sales に固定
- **売上店舗と出荷店舗**: ret_store_id / sales_ret_store_id / shipping_ret_store_id の3つを Fact に保持

## 対象ファイル

### 新規作成
- `database/migrations/2026_03_09_000001_create_ins_dim_tables.php`
- `database/migrations/2026_03_09_000002_create_ins_sales_fact_table.php`
- `database/migrations/2026_03_09_000003_create_ins_hourly_sales_fact_table.php`
- `database/migrations/2026_03_09_000004_create_ins_daily_summary_tables.php`
- `database/migrations/2026_03_09_000005_create_ins_monthly_summary_tables.php`
- `app/Models/Insights/*.php`（15モデル）

### 参照のみ（変更禁止）
- sakemaru-trade: ret_ テーブル群（構造調査のみ）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: DB調査 | 完了 | 2026-03-09 | 全テーブル構造確認済み |
| P1: マイグレーション作成 | 完了 | 2026-03-09 | 15テーブル作成済み → **Python ETL チーム開始可能** |
| P2: Eloquentモデル作成 | 完了 | 2026-03-09 | 15モデル作成済み → **UI チーム開始可能** |

---

## 作業中コンテキスト

### ret_テーブル構造（P0完了 2026-03-09）

**ret_daily_sales** ← **売上基準ソース**（13.8M行、2021-03-01〜2026-02-28）
- 日付カラム名: `slip_date`（NOT business_date）
- ret_store_id(bigint), item_code(varchar20), category_code(varchar20)
- sales_qty(int), sales_amount(int), return_qty(int), return_amount(int)
- gross_profit(int), cost_amount(decimal11,2)
- shipping_ret_store_id(bigint nullable), sales_ret_store_id(bigint nullable) ← **確認済み**
- その他: sales_pack_qty, cost_adjustment, cost_adj, internal_tax, tax_included_sales_amount, temporary_flag 等

**ret_hourly_sales** ← **時間帯基準ソース**
- ret_daily_sales と同じ構造 + time_slot(varchar10)
- 日付カラム名: `slip_date`

**ret_daily_settlement_summary** ← customer_count 取得元
- 日付カラム名: `slip_date`
- **注意: ret_store_register_id 単位（レジ単位）** → 店舗単位にするには SUM GROUP BY (slip_date, ret_store_id)
- customer_count(int), cash_customer_count, credit_customer_count, card_customer_count, voucher_customer_count 等
- net_sales(int), gross_sales(int), cash_sales, credit_sales, card_sales, voucher_sales 等
- **売上基準値として使わないこと**（あくまで customer_count と参考値）

**ret_stores** ← 存在確認: **存在する**
- code(char10), name(varchar100), type(enum), is_closed(tinyint)
- **area / region カラムは存在しない** → dim_store で独自管理するか、固定マッピングが必要
- 24店舗（RETAIL_STORE, is_closed=0）

**items（共有テーブル、ret_プレフィックスなし）** ← 商品マスタ
- code(int), name(varchar)
- **ret_items は存在しない**。共有 `items` テーブルを使用

**item_categories（共有テーブル、ret_プレフィックスなし）** ← カテゴリマスタ
- code(int), name(varchar), parent_id, depth
- 大分類: 100=酒類, 200=飲料水・食品, 300=ギフト, 400=雑貨, 500=たばこ・金券, 600=業務用食品, 700=空容器, 800=送料, 900=その他

**ret_item_categories** ← ブリッジテーブル（item_category_id FK + 税・ポイント設定のみ）

**精算系テーブル支払種別**:
- ret_store_settlement_registers: subtotal, voucher/credit/receivable の count+amount
- ret_store_settlement_credits: credit_code, credit_name, credit_count, credit_amount
- ret_store_settlement_emoney: emoney_code, emoney_name, emoney_amount
- ret_store_settlement_vouchers: voucher_code, voucher_name, voucher_quantity, voucher_amount
- ret_store_settlement_receivables: receivable_amount, customer_code, customer_name
- ret_store_settlement_cash: denomination_code, denomination_name, denomination_quantity, denomination_amount
- ret_store_settlement_charges: charge_code, charge_name, charge_amount
- ret_store_settlement_sales_vouchers: sales_voucher_code, sales_voucher_name, sales_voucher_quantity, sales_voucher_amount
- 全テーブル共通: ret_store_id, business_date, ret_store_register_id

**重要な設計修正点**:
1. ret_daily_sales の日付カラムは `slip_date` → ins_ では `business_date` にマッピング
2. ret_stores に area/region がない → dim_store で独自管理（初期値は手動 or NULL）
3. items/item_categories は共有テーブル（ret_なし） → ETL で名前を取得
4. settlement_summary はレジ単位 → SUM GROUP BY で店舗単位に集約

### マイグレーション情報（P1完了時に記入）
- マイグレーションファイル名: (実施後に記入)
- テーブル数: 15予定
- インデックス設計: (実施後に記入)

### Git ブランチ
- 作業ブランチ: feature/insights-db-design
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P0: DB調査
- 完了日: 2026-03-09
- 実績:
  - ret_daily_sales: 13.8M行、2021-03-01〜2026-02-28、slip_date(NOT business_date)
  - ret_hourly_sales: daily_sales + time_slot(varchar10)
  - ret_daily_settlement_summary: レジ単位（SUM GROUP BY必要）
  - ret_stores: 24店舗、area/regionカラムなし
  - items/item_categories: 共有テーブル（ret_なし）
  - shipping_ret_store_id / sales_ret_store_id: 確認済み
  - 精算系8テーブル: 全構造確認済み
- **完了時アクション**: manager-boot.md 更新済み

### P1: マイグレーション作成
- 完了日: 2026-03-09
- 成果物: database/migrations/ (5ファイル)
- 実績:
  - 15テーブル作成: dim(5) + fact(2) + daily(6) + monthly(2)
  - php artisan migrate 成功
  - 全ユニークインデックス・検索インデックス作成済み
- **完了時アクション**: manager-boot.md 更新済み

### P2: Eloquentモデル作成
- 完了日: 2026-03-09
- 成果物: app/Models/Insights/ (15ファイル)
- 実績:
  - 15モデル作成、全 getTable() 正常
  - $fillable, $casts 設定済み
- **完了時アクション**: manager-boot.md 更新済み
