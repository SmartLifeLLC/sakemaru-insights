# DB設計チーム 作業計画

## 前提

- sakemaru-insights: Laravel 12 + Filament v4（初期状態）
- DB_TABLE_PREFIX='ins_'（マイグレーションでは ins_ を付けない）
- 共通DB `sakemaru_hana_prod`
- 追加修正仕様: `../sakemaru_insights_prompt_addendum.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 | 後続チームへの影響 |
|---|-------|------|---------|-------------------|
| P0 | DB調査 | ret_テーブルの実カラム構造を確認 | カラム一覧をコンテキストに記録 | 全チームの設計基盤 |
| P1 | マイグレーション作成 | 15テーブルのマイグレーション | `php artisan migrate` 成功 | **Python ETL チーム開始可能** |
| P2 | Eloquentモデル作成 | 15モデル作成 | テーブルマッピング正常 | **UI チーム開始可能** |

---

## P0: DB調査

### 目的

ret_テーブルの実カラム構造を確認し、ins_テーブル設計に必要な情報を揃える。

### 調査手順

1. sakemaru-trade の .env から DB接続情報を取得
   ```bash
   cat /Users/jungsinyu/Projects/sakemaru-trade/.env | grep DB_
   ```

2. ret_テーブル一覧の確認
   ```sql
   SHOW TABLES LIKE 'ret_%';
   ```

3. 売上集計系テーブル（基準ソース）
   ```sql
   DESCRIBE ret_daily_sales;
   DESCRIBE ret_hourly_sales;
   DESCRIBE ret_daily_settlement_summary;
   ```

4. マスタテーブル（Dimension 元データ）
   ```sql
   DESCRIBE ret_stores;
   DESCRIBE ret_items;
   DESCRIBE ret_item_categories;
   ```

5. 精算系テーブル（支払分析用）
   ```sql
   DESCRIBE ret_store_settlement_registers;
   DESCRIBE ret_store_settlement_credits;
   DESCRIBE ret_store_settlement_emoney;
   DESCRIBE ret_store_settlement_vouchers;
   DESCRIBE ret_store_settlement_receivables;
   DESCRIBE ret_store_settlement_cash;
   DESCRIBE ret_store_settlement_charges;
   DESCRIBE ret_store_settlement_sales_vouchers;
   ```

6. サンプルデータ確認
   ```sql
   SELECT * FROM ret_daily_sales LIMIT 5;
   SELECT * FROM ret_hourly_sales LIMIT 5;
   SELECT * FROM ret_daily_settlement_summary LIMIT 5;
   ```

7. 支払種別の一覧抽出

### 完了条件

- 全対象テーブルのカラム名・型を boot.md に記録
- ret_stores / ret_items / ret_item_categories の存在有無を確認
- 支払種別の完全な一覧を特定
- sales_ret_store_id / shipping_ret_store_id の存在を確認
- **manager-boot.md の「共有コンテキスト > ret_テーブル構造」を更新すること**

---

## P1: マイグレーション作成

### 目的

15テーブル（Fact 2 + Dimension 5 + Daily Summary 6 + Monthly Summary 2）のマイグレーションを作成する。

### 前提

- P0 の調査結果に基づいてカラムを確定
- マイグレーション内テーブル名に `ins_` を含めない（DB_TABLE_PREFIX で自動付与）
- Summary テーブルはフラット構造（store_name, area, item_name 等を冗長保持）

### マイグレーション構成

5ファイルに分割:

#### 1. Dimensionテーブル

**dim_store**
- id, store_code, store_name, area, region, timestamps

**dim_item**
- id, item_code, item_name, category_code, brand, timestamps

**dim_category**
- id, category_code, category_name, timestamps

**dim_date**
- date(PK), year, month, day, weekday, week_of_year

**dim_time_slot**
- id, time_slot, label

#### 2. 基本Factテーブル（sales_fact）

粒度: 日 × 店舗 × 商品
- id, business_date, ret_store_id, sales_ret_store_id, shipping_ret_store_id
- item_code, category_code
- sales_qty, sales_amount, return_qty, return_amount, gross_profit, cost_amount
- timestamps

インデックス:
- UNIQUE: (business_date, ret_store_id, item_code)
- INDEX: (business_date, ret_store_id)
- INDEX: (business_date, item_code)
- INDEX: (ret_store_id, business_date)

データソース: ret_daily_sales

#### 3. 時間帯Factテーブル（hourly_sales_fact）

粒度: 日 × 店舗 × 商品 × 時間帯
- id, business_date, ret_store_id, time_slot
- item_code, category_code
- sales_qty, sales_amount, return_qty, return_amount, gross_profit, cost_amount
- timestamps

インデックス:
- UNIQUE: (business_date, ret_store_id, item_code, time_slot)
- INDEX: (business_date, ret_store_id, time_slot)
- INDEX: (ret_store_id, business_date)

データソース: ret_hourly_sales

#### 4. Daily Summaryテーブル

**daily_store_sales**（日 × 店舗）
- id, business_date, ret_store_id, store_name, area
- sales_amount, sales_qty, return_amount, return_qty
- gross_profit, customer_count, unit_price, gross_profit_rate
- timestamps
- UNIQUE: (business_date, ret_store_id)
- INDEX: (ret_store_id, business_date), (business_date), (area, business_date)

**daily_item_sales**（日 × 商品）
- id, business_date, item_code, item_name, category_code, category_name
- sales_amount, sales_qty, return_amount, return_qty, gross_profit
- timestamps
- UNIQUE: (business_date, item_code)
- INDEX: (item_code, business_date), (category_code, business_date), (business_date)

**daily_category_sales**（日 × カテゴリ）
- id, business_date, category_code, category_name
- sales_amount, sales_qty, return_amount, gross_profit
- timestamps
- UNIQUE: (business_date, category_code)
- INDEX: (category_code, business_date), (business_date)

**daily_store_item_sales**（日 × 店舗 × 商品）
- id, business_date, ret_store_id, store_name
- item_code, item_name, category_code, category_name
- sales_amount, sales_qty, return_amount, gross_profit
- timestamps
- UNIQUE: (business_date, ret_store_id, item_code)
- INDEX: (ret_store_id, business_date), (item_code, business_date)

**hourly_store_sales**（日 × 店舗 × 時間帯）
- id, business_date, ret_store_id, store_name, time_slot
- sales_amount, sales_qty, gross_profit, customer_count
- timestamps
- UNIQUE: (business_date, ret_store_id, time_slot)
- INDEX: (ret_store_id, business_date), (business_date, time_slot)

**daily_payment_summary**（日 × 店舗 × 支払種別）
- id, business_date, ret_store_id, store_name
- payment_type, payment_label, amount, count
- timestamps
- UNIQUE: (business_date, ret_store_id, payment_type)
- INDEX: (ret_store_id, business_date), (payment_type, business_date)

#### 5. Monthly Summaryテーブル

**monthly_store_sales**（月 × 店舗）
- id, year_month, ret_store_id, store_name, area
- sales_amount, sales_qty, return_amount, return_qty
- gross_profit, customer_count, unit_price, gross_profit_rate
- timestamps
- UNIQUE: (year_month, ret_store_id)
- INDEX: (ret_store_id, year_month), (year_month)

**monthly_item_sales**（月 × 商品）
- id, year_month, item_code, item_name, category_code, category_name
- sales_amount, sales_qty, return_amount, gross_profit
- timestamps
- UNIQUE: (year_month, item_code)
- INDEX: (item_code, year_month), (year_month)

### 修正対象ファイル

- `database/migrations/2026_03_09_000001_create_ins_dim_tables.php`
- `database/migrations/2026_03_09_000002_create_ins_sales_fact_table.php`
- `database/migrations/2026_03_09_000003_create_ins_hourly_sales_fact_table.php`
- `database/migrations/2026_03_09_000004_create_ins_daily_summary_tables.php`
- `database/migrations/2026_03_09_000005_create_ins_monthly_summary_tables.php`

### 完了条件

- `php artisan migrate` が成功
- `SHOW TABLES LIKE 'ins_%'` で全15テーブルが確認できる
- ユニークインデックスと検索インデックスが正しく作成されている
- **manager-boot.md の「共有コンテキスト > マイグレーション完了情報」を更新すること**
- ※カラム構成は P0 の調査結果により変更の可能性あり

---

## P2: Eloquentモデル作成

### 目的

15テーブルに対応する Eloquent モデルを作成する。

### 修正方針

`app/Models/Insights/` に15モデルを作成:

- 全モデルで `$table` を明示（プレフィックスはLaravel自動付与）
- `$fillable` を適切に設定
- リレーションは設定しない（FK禁止、フラット構造方針）
- `$casts` を設定（date, decimal 等）

| モデル | テーブル | 備考 |
|--------|---------|------|
| SalesFact | sales_fact | 基本Fact（日×店舗×商品） |
| HourlySalesFact | hourly_sales_fact | 時間帯Fact（日×店舗×商品×時間帯） |
| DimStore | dim_store | Dimension |
| DimItem | dim_item | Dimension |
| DimCategory | dim_category | Dimension |
| DimDate | dim_date | PK=date |
| DimTimeSlot | dim_time_slot | Dimension |
| DailyStoreSales | daily_store_sales | Daily Summary |
| DailyItemSales | daily_item_sales | Daily Summary |
| DailyCategorySales | daily_category_sales | Daily Summary |
| DailyStoreItemSales | daily_store_item_sales | Daily Summary |
| HourlyStoreSales | hourly_store_sales | Daily Summary |
| DailyPaymentSummary | daily_payment_summary | Daily Summary |
| MonthlyStoreSales | monthly_store_sales | Monthly Summary |
| MonthlyItemSales | monthly_item_sales | Monthly Summary |

### 修正対象ファイル

- `app/Models/Insights/*.php`（15ファイル新規作成）

### 完了条件

- 全15モデルが作成されている
- `tinker` で各モデルの `getTable()` が正しいテーブル名を返す
- `$fillable` / `$casts` が適切に設定されている
- **manager-boot.md の「共有コンテキスト > モデル完了情報」を更新すること**

---

## 制約（厳守）

1. FK禁止（ins_ → ret_）
2. ret_変更禁止（index追加も原則禁止）
3. マイグレーション内テーブル名に `ins_` を含めない
4. Summary はフラット構造（ラベル冗長保持）
5. 破壊的操作禁止
