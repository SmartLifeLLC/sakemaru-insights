# 商品コード・カテゴリコード マッピング修正

- **作成日**: 2026-03-09
- **最終更新**: 2026-03-09
- **ステータス**: ins_側修正完了（ret_側データ再投入待ち）
- **問題**: ins_ テーブルの `item_name` が全て NULL
- **原因**: ret_daily_sales.item_code（9桁varchar）と items.code（6桁int）の型・桁不一致
- **対応**: ret_側マイグレーション完了（item_code→uint, item_id/item_category_id追加）、ins_側マイグレーション+ETL+モデル修正完了
- **影響範囲**: sakemaru（ret_側）、sakemaru-insights（ins_側 ETL）

---

## 1. 背景

### 現状のデータ構造

```
ret_daily_sales.item_code = "211523000"  ← 9桁 varchar(20)
                                ^^^^^^~~~
                                先頭6桁   末尾3桁（販売単位バリアント）

items.code = 211523                     ← 6桁 int
```

- **先頭6桁** が `items.code` に対応（マッチ率 100%）
- **末尾3桁** は販売単位を表す（`000`=バラ, `100`=ケース, `900`=箱 等）
- 同一商品が複数の末尾バリアントで売上記録される

### カテゴリの状況

```
ret_daily_sales.category_code = "211"   ← 3桁 varchar(20)
item_categories.code = 211              ← 3桁 int
```

- カテゴリは桁数が一致しており、CAST すればマッチする
- 現行 ETL でも `category_name` は正しく取得できている

### 店舗の状況

```
ret_daily_sales.ret_store_id = 1        ← bigint unsigned
ret_stores.id = 1                       ← bigint unsigned
```

- 店舗は ID で直接結合。問題なし

---

## 2. 問題の詳細

### 影響を受けるテーブル（item_name が NULL）

| テーブル | item_name | category_name | store_name |
|----------|-----------|---------------|------------|
| `ins_daily_item_sales` | **NULL** | OK | - |
| `ins_daily_store_item_sales` | **NULL** | OK | OK |
| `ins_monthly_item_sales` | **NULL** | OK | - |
| `ins_dim_item` | **NULL** | - | - |

### 影響を受けないテーブル

| テーブル | 理由 |
|----------|------|
| `ins_daily_store_sales` | item_name 列なし |
| `ins_daily_category_sales` | category_name は正常 |
| `ins_hourly_store_sales` | item_name 列なし |
| `ins_daily_payment_summary` | item_name 列なし |
| `ins_monthly_store_sales` | item_name 列なし |
| `ins_sales_fact` | item_name 列なし |
| `ins_hourly_sales_fact` | item_name 列なし |
| `ins_dim_store` | 無関係 |
| `ins_dim_category` | category_name は正常 |
| `ins_dim_date`, `ins_dim_time_slot` | 無関係 |

---

## 3. 修正方針

修正は **2つのシステムに分離** して実施する。

### 概念図

```
┌─────────────────────────────┐
│  sakemaru（ret_側）          │
│                             │
│  ret_daily_sales            │
│    item_code = "211523000"  │  ← 現状: 9桁コードのみ
│    (item_id なし)           │  ← 修正: item_id カラム追加
│                             │
│  ret_hourly_sales           │
│    item_code = "211523000"  │  ← 同上
│    (item_id なし)           │
│                             │
│  items                      │
│    id = 211523              │
│    code = 211523 (6桁 int)  │
│    name = "コカコーラ..."    │
└──────────────┬──────────────┘
               │
               │ ETL（Python）
               │
┌──────────────▼──────────────┐
│  sakemaru-insights（ins_側） │
│                             │
│  ins_daily_item_sales       │
│    item_code = "211523000"  │  ← 9桁そのまま保持
│    item_name = NULL ✗       │  ← JOIN 失敗で NULL
│                             │
└─────────────────────────────┘
```

---

## 4. sakemaru（ret_側）への依頼事項

### 4-A. ret_daily_sales / ret_hourly_sales への item_id 追加（推奨・長期）

**目的**: 商品マスタとの結合を正規化する

```sql
-- 案: item_id カラムを追加
ALTER TABLE ret_daily_sales ADD COLUMN item_id BIGINT UNSIGNED NULL AFTER item_code;
ALTER TABLE ret_hourly_sales ADD COLUMN item_id BIGINT UNSIGNED NULL AFTER item_code;

-- 既存データのバックフィル
UPDATE ret_daily_sales ds
JOIN items i ON CAST(LEFT(ds.item_code, 6) AS UNSIGNED) = i.code
SET ds.item_id = i.id
WHERE ds.item_id IS NULL;

-- 同様に ret_hourly_sales
UPDATE ret_hourly_sales hs
JOIN items i ON CAST(LEFT(hs.item_code, 6) AS UNSIGNED) = i.code
SET hs.item_id = i.id
WHERE hs.item_id IS NULL;
```

**注意事項**:
- ret_daily_sales は **約1,380万行**。UPDATE は負荷が大きいためオフピーク時に実行
- POS データ投入時に `item_id` を同時に書き込むよう POS 連携プログラムの修正も必要
- `item_code` の先頭6桁 → `items.code` の対応は 100% 確認済み

### 4-B. item_code の構造をドキュメント化（即時）

**目的**: 末尾3桁の仕様を明確にする

確認が必要な事項:
- 末尾3桁の正式な仕様（販売単位コード？）
- `000` = バラ売り、`100` = ケース、`900` = 箱売り？
- `item_code_s` カラムの用途

---

## 5. sakemaru-insights（ins_側 ETL）の修正

### 即時対応（ret_側の修正を待たずに実施可能）

ETL の transform.py で、`item_code` の先頭6桁を使って `items` テーブルと JOIN する。

#### 修正ファイル: `scripts/etl/etl/transform.py`

**修正箇所**: 商品名 JOIN を行う全関数

| 関数 | 行 | 修正内容 |
|------|-----|---------|
| `build_daily_item_sales()` | L182-183 | JOIN キーを先頭6桁に変更 |
| `build_daily_store_item_sales()` | L289-290 | 同上 |
| `build_monthly_item_sales()` | L453-454 | daily_item_sales 経由のため自動修正 |
| `build_dim_items()` | L500 | dim_item の item_code は items.code（6桁）を使用 |

**修正パターン**（全箇所共通）:

```python
# 修正前
agg = agg.join(items.select(["item_code", "item_name"]), on="item_code", how="left")

# 修正後
agg = agg.with_columns(
    pl.col("item_code").str.slice(0, 6).alias("_item_code_6")
)
agg = agg.join(
    items.select(["item_code", "item_name"]).rename({"item_code": "_item_code_6"}),
    on="_item_code_6",
    how="left",
).drop("_item_code_6")
```

#### 修正ファイル: `scripts/etl/etl/extract.py`

**修正なし**。`extract_items()` は `items.code` を文字列キャストして返しており、6桁の値が返る。これは正しい。

#### 修正ファイル: `scripts/etl/etl/load.py`

**修正なし**。UPSERT ロジックに変更不要。

### ins_ テーブルスキーマ

**変更なし**。`item_code varchar(20)` は ret_daily_sales の9桁生値をそのまま保持する。商品名検索は `item_name` カラム（フラット構造）で行う。

### データ再投入

修正後、以下を実行:

```bash
# ins_ テーブルの item_name を持つテーブルのみ TRUNCATE
mysql -u root sakemaru_hana_prod -e "
TRUNCATE TABLE ins_daily_item_sales;
TRUNCATE TABLE ins_daily_store_item_sales;
TRUNCATE TABLE ins_monthly_item_sales;
TRUNCATE TABLE ins_dim_item;
"

# ETL 再実行
python3 scripts/etl/generate_retail_stats.py --mode=daily --date=2026-02-28
```

---

## 6. 将来対応（ret_側に item_id 追加後）

ret_daily_sales に `item_id` が追加された場合、ETL を以下のように最適化できる:

```python
# extract.py: item_id を SELECT に追加
SELECT ..., item_id FROM ret_daily_sales

# transform.py: item_id で直接 JOIN（先頭6桁の文字列操作が不要になる）
agg = agg.join(items.select(["item_id", "item_name"]), on="item_id", how="left")
```

ただし、この対応は ret_側の修正完了後に行う。現時点では先頭6桁 JOIN で問題ない。

---

## 7. 検証方法

修正後に以下を確認:

```sql
-- item_name が NULL でないことを確認
SELECT COUNT(*), COUNT(item_name) FROM ins_daily_item_sales;

-- サンプル確認
SELECT item_code, item_name, sales_amount
FROM ins_monthly_item_sales
ORDER BY sales_amount DESC
LIMIT 10;

-- dim_item の確認
SELECT item_code, item_name FROM ins_dim_item LIMIT 10;
```
