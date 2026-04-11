# Python ETLチーム 作業計画

## 前提

- DB設計チームが P1（マイグレーション）を完了済みであること
- ins_ テーブル群が存在していること
- ret_ テーブルの構造は ../manager-boot.md の「共有コンテキスト」を参照
- 追加修正仕様: `../sakemaru_insights_prompt_addendum.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P3 | Python ETL基盤 | ret_ → ins_ のETLスクリプト作成 | ETL正常実行 + 検算一致 |
| P4 | Laravel実行フロー | Artisanコマンド + Scheduler | コマンド実行 + スケジュール登録 |

---

## P3: Python ETL基盤

### 目的

ret_テーブルから ins_テーブルへデータを変換・集計するETLスクリプトを作成する。

### 技術選定

Python + Polars（高速集計）+ SQLAlchemy（DB接続）

### スクリプト構成

```
scripts/etl/
├── generate_retail_stats.py    # メインETLスクリプト
├── requirements.txt            # polars, sqlalchemy, pymysql, python-dotenv
├── config.py                   # DB接続設定（.envから読み取り）
└── etl/
    ├── __init__.py
    ├── extract.py              # ret_テーブルからデータ抽出
    ├── transform.py            # 集計・変換ロジック
    └── load.py                 # ins_テーブルへUPSERT
```

### Extract（extract.py）

データ取得元（基準ソース別）:

| ソース | 用途 | 重要度 |
|--------|------|--------|
| ret_daily_sales | sales_amount, sales_qty, gross_profit, cost_amount の**唯一の基準** | 最重要 |
| ret_hourly_sales | 時間帯分析 | 必須 |
| ret_daily_settlement_summary | customer_count の取得 | 補助 |
| ret_store_settlement_* (8テーブル) | 支払種別分析（payment_type別の金額・件数） | 補助 |
| ret_stores | Dimension（店舗名・エリア） | マスタ |
| ret_items | Dimension（商品名） | マスタ |
| ret_item_categories | Dimension（カテゴリ名） | マスタ |

対象期間をパラメータで指定可能（デフォルト: 当日）。

### Transform（transform.py）

集計の基本ルール:
- sales 系は **ret_daily_sales 起点**（絶対ルール）
- hourly 系は **ret_hourly_sales 起点**
- payment 系は **settlement 系起点**
- customer_count は **ret_daily_settlement_summary** から取得

Fact 生成:
- ret_daily_sales → ins_sales_fact（日×店舗×商品）
- ret_hourly_sales → ins_hourly_sales_fact（日×店舗×商品×時間帯）

Daily Summary 生成:
- groupby(store, date) → daily_store_sales ※customer_count は settlement_summary から補完
- groupby(item, date) → daily_item_sales
- groupby(category, date) → daily_category_sales
- groupby(store, date, item) → daily_store_item_sales
- ret_hourly_sales → groupby(store, date, time_slot) → hourly_store_sales
- settlement系 → groupby(store, date, payment_type) → daily_payment_summary

Monthly Summary 生成:
- daily_store_sales の月次集計 → monthly_store_sales
- daily_item_sales の月次集計 → monthly_item_sales

ディメンション名寄せ（ETL時に付与）:
- store_name, area（ret_stores から）
- item_name（ret_items から）
- category_name（ret_item_categories から）
- payment_label（payment_type から変換）
- unit_price = sales_amount / customer_count（customer_count > 0）
- gross_profit_rate = gross_profit / sales_amount * 100（sales_amount > 0）

### Load（load.py）

- INSERT ... ON DUPLICATE KEY UPDATE による UPSERT
- バッチサイズ指定可能（デフォルト: 1000）
- **検算ログ出力**: ret_ 合計値と ins_ 合計値の比較を標準出力に表示

### 更新モード

| モード | 用途 | 対象テーブル |
|--------|------|-------------|
| `--mode=realtime` | 15分間隔 | hourly_store_sales + 当日分 daily_store_sales 差分 |
| `--mode=daily` | 日次締め後 | 全テーブル（Fact + Daily + Monthly） |
| `--mode=full` | 全期間再集計 | 全テーブル（初回構築・ロジック変更時） |

### 修正対象ファイル

- `scripts/etl/generate_retail_stats.py`（新規）
- `scripts/etl/requirements.txt`（新規）
- `scripts/etl/config.py`（新規）
- `scripts/etl/etl/__init__.py`（新規）
- `scripts/etl/etl/extract.py`（新規）
- `scripts/etl/etl/transform.py`（新規）
- `scripts/etl/etl/load.py`（新規）

### 完了条件

- `python scripts/etl/generate_retail_stats.py --mode=daily` が正常完了
- ins_テーブルにデータが投入される
- **検算**: ret_daily_sales の売上合計 = ins_daily_store_sales の売上合計
- **検算**: 帳票間で売上数字が一致（エリア別日報の合計 = 商品別売上の合計）
- エラーハンドリング: DB接続エラー、データ不整合時にログ出力

---

## P4: Laravel実行フロー

### 目的

Python ETL を Laravel から管理・実行する仕組みを構築する。

### Artisanコマンド

```php
// app/Console/Commands/GenerateStatsCommand.php
class GenerateStatsCommand extends Command
{
    protected $signature = 'insights:generate-stats {--mode=daily}';
}
```

- `Process::run()` で Python スクリプトを呼び出し
- 実行結果をログに記録
- 終了コードで成功/失敗を判定

### Scheduler 登録（routes/console.php）

- 15分間隔: `insights:generate-stats --mode=realtime`
- 毎日締め後: `insights:generate-stats --mode=daily`

### 修正対象ファイル

- `app/Console/Commands/GenerateStatsCommand.php`（新規）
- `routes/console.php`（既存変更）

### 完了条件

- `php artisan insights:generate-stats --mode=daily` が正常実行
- `php artisan schedule:list` で realtime/daily が確認できる
- 実行結果がログに記録される

---

## 制約（厳守）

1. 売上基準は ret_daily_sales に固定（settlement を売上基準に使わない）
2. UPSERT で冪等に実装（TRUNCATE / DROP 禁止）
3. ret_ テーブルは参照のみ（変更禁止）
4. ins_ テーブル名は DB_TABLE_PREFIX='ins_' を考慮（テーブル名に ins_ を含めない）
5. 検算ログを必ず出力
