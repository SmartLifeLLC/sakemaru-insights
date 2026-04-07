# 酒丸算盤 小売統計基盤 追加修正プロンプト

- 作成日: 2026-03-09
- 対象: 酒丸アーキテクチャAI / 酒丸勘定衆・乃蓮・算盤に設計指示を出すための追加修正内容
- 目的: 既存の作業計画・設計プロンプトに対して、**小売統計基盤の設計方針・統計対象・Fact/Dimension設計・ETL方針・性能方針**を不足なく追加する
- 対象範囲: **小売用のみ**（sakemaru-trade / ret_ → sakemaru-insights / ins_）
- 除外: **在庫金額レポートは今回の対象外**

---

# 1. この追加修正の位置づけ

この文書は、既存の以下の計画・プロンプトに対する**追加修正指示**である。

- 小売統計基盤 構築 作業計画
- 酒丸統計基盤 設計調査プロンプト
- 酒丸算盤の Fact / Dimension / ETL / UI 設計指示

この追加修正では、特に以下を明確化する。

1. 小売統計基盤の全体方針
2. raw データと analytics データの責務分離
3. 統計対象帳票の明確化
4. Fact / Dimension / Aggregate テーブルの役割
5. 粒度（grain）の統一
6. Python ETL の責務
7. 酒丸算盤で高速表示するための設計原則

---

# 2. システム前提の再整理

酒丸シリーズは共通DB上の複数Laravelシステムで構成される。

## 2.1 小売統計に関係するシステム

- sakemaru-trade = 酒丸乃蓮
  - 小売管理システム
  - rawデータの保持元
- sakemaru-insights = 酒丸算盤
  - 統計・分析システム
  - 統計テーブルの保持先

## 2.2 データ保存方針

- rawデータは **sakemaru-trade (ret_)** が保持する
- 統計データは **sakemaru-insights (ins_)** が保持する
- 算盤は **ret_ テーブルを直接参照しない**
- 表示は **ins_ テーブルのみ** を参照する

## 2.3 技術方針

- sakemaru-insights は Laravel 12 + Filament v4
- ETL は Python で実装する
- Laravel / Filament から Python を実行する
- ret_ テーブルは参照専用であり、変更禁止
- FK制約は張らない
- JOINを極力不要にするフラット構造を優先する

---

# 3. 小売統計基盤の全体構成

小売統計基盤は、以下の3層構造で設計する。

```text
ret_ raw tables
    ↓
Python ETL
    ↓
ins_ analytics tables
    ↓
Filament UI / 帳票 / ダッシュボード
```

より具体的には以下。

```text
sakemaru-trade (ret_)
  - 旧システム実績保管用 raw テーブル
  - 日別売上 / 時間帯売上 / 精算サマリ / レジ精算明細

        ↓ Python ETL

sakemaru-insights (ins_)
  - Fact テーブル
  - Dimension テーブル
  - Daily Summary テーブル
  - Monthly Summary テーブル

        ↓

酒丸算盤 UI
  - 売上日報
  - エリア別売上
  - 売上報告書
  - 日計表
  - 商品別売上
  - 時間帯別売上
  - 月次レポート
```

---

# 4. raw データの前提

小売統計の raw layer は、sakemaru-trade 側に作成する以下の ret_ テーブル群を前提とする。

## 4.1 T1 レジ精算系

- ret_store_settlement_registers
- ret_store_settlement_credits
- ret_store_settlement_charges
- ret_store_settlement_vouchers
- ret_store_settlement_voucher_details
- ret_store_settlement_sales_vouchers
- ret_store_settlement_cash
- ret_store_settlement_emoney
- ret_store_settlement_receivables

用途:

- 支払方法別分析
- レジ精算実績分析
- 受取金券・電子マネー・クレジット等の分析
- 日計表・売上報告書の支払内訳

## 4.2 T2 売上集計系

- ret_daily_sales
- ret_daily_settlement_summary
- ret_hourly_sales

用途:

- 日別商品売上
- 店舗別日次統計
- 時間帯別売上分析
- 商品別売上分析
- 月次集計の元データ

---

# 5. 統計設計で最重要の原則

## 5.1 粒度（grain）を統一すること

この設計で最も重要なのは、**売上・数量・粗利の基準粒度を統一すること**である。

ret_ の raw データには以下のように異なる粒度が混在している。

- ret_daily_sales: 日 × 店舗 × 商品
- ret_daily_settlement_summary: 日 × 店舗 × 端末
- ret_hourly_sales: 日 × 店舗 × 時間帯 × 商品

このまま混ぜて集計すると、売上合計の不一致が発生する。

例:

- 商品売上合計とレジ精算総売上がズレる
- 値引、返品、税処理、レジ修正等で数字差異が出る
- ダッシュボード上で「店舗日報の売上」と「商品別売上合計」が一致しない

## 5.2 売上・数量・粗利の唯一の基準ソース

**売上・数量・粗利は必ず `ret_daily_sales` を基準にすること。**

つまり以下を絶対ルールとする。

- sales_amount は ret_daily_sales を基準にする
- sales_qty は ret_daily_sales を基準にする
- gross_profit は ret_daily_sales を基準にする
- cost_amount は ret_daily_sales を基準にする

## 5.3 settlement系の役割

`ret_daily_settlement_summary` や `ret_store_settlement_*` は以下の補助データとして扱う。

- customer_count
- payment_type 別件数
- payment_type 別金額
- レジ精算関連指標

**売上の基準値として使わないこと。**

---

# 6. 酒丸特有の注意点

## 6.1 売上店舗と出荷店舗の違い

小売 raw データには以下が含まれる。

- shipping_ret_store_id
- sales_ret_store_id

酒丸では、配送・横持ち・店舗間移動の文脈により、**売上店舗と出荷店舗が一致しない可能性がある**。

そのため、Fact テーブルには以下を必ず保持すること。

- ret_store_id
- sales_ret_store_id
- shipping_ret_store_id

少なくとも保存上は保持し、後続の分析で切り分けられるようにすること。

---

# 7. 統計DBの基本構造

統計DBは以下の4層で構成する。

```text
Dimension
Fact
Daily Aggregate
Monthly Aggregate
```

より実務的には以下。

```text
raw (ret_)
  ↓
Fact (ins_sales_fact)
  ↓
Daily Summary
  ↓
Monthly Summary
```

---

# 8. Fact テーブル設計方針

## 8.1 Fact テーブルの役割

Fact テーブルは、売上分析の中心になるテーブルであり、
**すべての統計・帳票・BI・AI分析の基盤**になる。

## 8.2 基本 Fact の粒度

基本 Fact の粒度は以下とする。

```text
日 × 店舗 × 商品
```

この粒度の Fact テーブル名は以下とする。

- ins_sales_fact

## 8.3 ins_sales_fact の主な保持項目

- business_date
- ret_store_id
- sales_ret_store_id
- shipping_ret_store_id
- item_code
- category_code
- sales_qty
- sales_amount
- return_qty
- return_amount
- cost_amount
- gross_profit

必要に応じて以下も保持可能。

- sales_pack_qty
- internal_tax
- tax_included_sales_amount
- sales_tax_type
- special_sale_type
- set_type
- stock_control_type
- handling_rank
- cost_confirmed_flag
- history_no
- temporary_flag

## 8.4 Fact のデータソース

ins_sales_fact は **ret_daily_sales から生成すること。**

---

# 9. 時間帯 Fact の扱い

`ret_hourly_sales` は行数が非常に多く、基本 Fact と同居させると Fact が肥大化する。

そのため、時間帯分析用の Fact は別テーブルとする。

## 9.1 時間帯 Fact

- ins_hourly_sales_fact

粒度:

```text
日 × 店舗 × 商品 × 時間帯
```

主な項目:

- business_date
- ret_store_id
- time_slot
- item_code
- category_code
- sales_qty
- sales_amount
- return_qty
- return_amount
- gross_profit
- cost_amount

データソース:

- ret_hourly_sales

---

# 10. Dimension テーブル設計方針

Dimension テーブルは、**分析軸を表すマスタ**である。

Fact と Dimension を分離した構造は Star Schema の基本であり、
後続の分析・拡張・説明性に有利である。

ただし、今回の表示系では JOIN を減らすため、Summary テーブル側にラベル類を冗長保持してよい。
Dimension はあくまで分析基盤・整合用として持つ。

## 10.1 必須 Dimension

### ins_dim_store

用途:

- 店舗軸
- エリア別売上
- 店舗別日報

保持例:

- id
- store_code
- store_name
- area
- region

元データ候補:

- ret_stores

### ins_dim_item

用途:

- 商品軸
- 商品別売上

保持例:

- id
- item_code
- item_name
- category_code
- brand

元データ候補:

- ret_items

### ins_dim_category

用途:

- カテゴリ軸

保持例:

- id
- category_code
- category_name

元データ候補:

- ret_item_categories

### ins_dim_date

用途:

- 年月日軸
- 月次・曜日・週次分析

保持例:

- date
- year
- month
- day
- weekday
- week_of_year

### ins_dim_time_slot

用途:

- 時間帯軸

保持例:

- id
- time_slot
- label

---

# 11. Aggregate テーブルが必要な理由

Fact を毎回集計して一覧やダッシュボードを表示すると、データ量増大時に確実に遅くなる。

そのため、**表示用の事前集計テーブル（Aggregate / Summary）を持つことを必須とする。**

考え方:

```text
Fact は分析基盤
Summary は表示基盤
```

つまり、酒丸算盤の画面は基本的に Fact を直接集計せず、Summary テーブルを直接読む。

これにより以下を実現する。

- クエリ50ms以内
- ダッシュボード高速化
- JOIN / GROUP BY 最小化
- 大量データ対応

---

# 12. Daily Summary テーブル設計方針

## 12.1 ins_daily_store_sales

用途:

- エリア別売上日報
- 売上日報
- 店舗別日次サマリ

粒度:

```text
日 × 店舗
```

保持項目例:

- business_date
- ret_store_id
- store_name
- area
- sales_amount
- sales_qty
- return_amount
- return_qty
- gross_profit
- customer_count
- unit_price
- gross_profit_rate

注記:

- sales 系は ret_daily_sales 起点で集計
- customer_count は ret_daily_settlement_summary を利用して補完してよい
- unit_price, gross_profit_rate は保存してもよいし画面計算でもよいが、高速表示優先なら保持してよい

## 12.2 ins_daily_item_sales

用途:

- 商品別売上
- 商品別ランキング

粒度:

```text
日 × 商品
```

保持項目例:

- business_date
- item_code
- item_name
- category_code
- category_name
- sales_amount
- sales_qty
- return_amount
- return_qty
- gross_profit

## 12.3 ins_daily_category_sales

用途:

- カテゴリ別売上

粒度:

```text
日 × カテゴリ
```

保持項目例:

- business_date
- category_code
- category_name
- sales_amount
- sales_qty
- return_amount
- gross_profit

## 12.4 ins_daily_store_item_sales

用途:

- 店舗別商品売上
- 店舗内商品ランキング

粒度:

```text
日 × 店舗 × 商品
```

保持項目例:

- business_date
- ret_store_id
- store_name
- item_code
- item_name
- category_code
- category_name
- sales_amount
- sales_qty
- return_amount
- gross_profit

## 12.5 ins_hourly_store_sales

用途:

- 時間帯別売上
- 来店ピーク分析

粒度:

```text
日 × 店舗 × 時間帯
```

保持項目例:

- business_date
- ret_store_id
- store_name
- time_slot
- sales_amount
- sales_qty
- gross_profit
- customer_count

注記:

- 売上・数量・粗利は ret_hourly_sales を基準
- customer_count は取得できる範囲で補完。取得困難なら nullable でよい

## 12.6 ins_daily_payment_summary

用途:

- 日計表
- 売上報告書の支払内訳
- 支払方法別分析

粒度:

```text
日 × 店舗 × 支払種別
```

保持項目例:

- business_date
- ret_store_id
- store_name
- payment_type
- payment_label
- amount
- count

支払種別例:

- cash
- credit
- emoney
- voucher
- receivable
- charge
- sales_voucher

注記:

- データソースは ret_store_settlement_registers / ret_store_settlement_credits / ret_store_settlement_emoney / ret_store_settlement_vouchers / ret_store_settlement_receivables など
- 支払系は売上基準値としてではなく、内訳分析として扱う

---

# 13. Monthly Summary テーブル設計方針

## 13.1 ins_monthly_store_sales

用途:

- 月次売上レポート
- 月次トレンド分析
- 店舗別月次比較

粒度:

```text
月 × 店舗
```

保持項目例:

- year_month
- ret_store_id
- store_name
- area
- sales_amount
- sales_qty
- return_amount
- return_qty
- gross_profit
- customer_count
- unit_price
- gross_profit_rate

## 13.2 ins_monthly_item_sales

用途:

- 商品別月次推移
- 月次ランキング

粒度:

```text
月 × 商品
```

保持項目例:

- year_month
- item_code
- item_name
- category_code
- category_name
- sales_amount
- sales_qty
- return_amount
- gross_profit

---

# 14. 統計対象帳票の明確化

今回の小売統計基盤で対応対象とする帳票は以下。

## 14.1 対象帳票

1. エリア別売上日報
2. 売上報告書
3. 日計表
4. 商品別売上
5. 時間帯別売上
6. 月次売上レポート

## 14.2 対象外

- 在庫金額報告書

---

# 15. 帳票ごとの対応テーブル

## 15.1 エリア別売上日報

用途:

- 店舗別の日次売上確認
- エリア別比較

主テーブル:

- ins_daily_store_sales
- 必要に応じて ins_dim_store

主な表示項目:

- 店舗名
- エリア
- 売上
- 客数
- 粗利
- 客単価
- 粗利率

## 15.2 売上報告書

用途:

- 店舗売上サマリ
- 支払方法別内訳の表示

主テーブル:

- ins_daily_store_sales
- ins_daily_payment_summary

主な表示項目:

- 売上
- 客数
- 粗利
- 現金
- クレジット
- 電子マネー
- 金券
- 売掛

## 15.3 日計表

用途:

- 支払方法別売上集計

主テーブル:

- ins_daily_payment_summary

主な表示項目:

- 店舗
- 支払種別
- 金額
- 件数

## 15.4 商品別売上

用途:

- 商品別売上ランキング
- 商品分析

主テーブル:

- ins_daily_item_sales
- 必要に応じて ins_daily_store_item_sales

## 15.5 時間帯別売上

用途:

- 来店ピーク分析
- 時間帯別売上推移

主テーブル:

- ins_hourly_store_sales

## 15.6 月次売上レポート

用途:

- 月次推移
- 月別比較

主テーブル:

- ins_monthly_store_sales
- ins_monthly_item_sales

---

# 16. Python ETL の責務

Python ETL は以下の責務を持つ。

1. raw データを抽出する
2. Fact テーブルを生成する
3. Aggregate テーブルを生成する
4. 冪等に UPSERT する
5. 検算可能なログを残す

## 16.1 処理モード

### realtime

用途:

- 15分間隔の高速更新

対象:

- ins_hourly_store_sales
- 必要に応じて当日分の ins_daily_store_sales のみ差分更新

### daily

用途:

- 日次締め後更新

対象:

- ins_sales_fact
- ins_hourly_sales_fact
- ins_daily_store_sales
- ins_daily_item_sales
- ins_daily_category_sales
- ins_daily_store_item_sales
- ins_hourly_store_sales
- ins_daily_payment_summary
- ins_monthly_store_sales
- ins_monthly_item_sales

### full

用途:

- 全期間再集計
- 初回構築時
- ロジック変更時の再構築

---

# 17. Python ETL の実装原則

## 17.1 Extract

データ取得元:

- ret_daily_sales
- ret_hourly_sales
- ret_daily_settlement_summary
- ret_store_settlement_registers
- ret_store_settlement_credits
- ret_store_settlement_emoney
- ret_store_settlement_vouchers
- ret_store_settlement_receivables
- 必要に応じて ret_store_settlement_cash / ret_store_settlement_charges / ret_store_settlement_sales_vouchers
- マスタ取得用に ret_stores / ret_items / ret_item_categories

## 17.2 Transform

集計の基本ルール:

- sales 系は ret_daily_sales 起点
- hourly 系は ret_hourly_sales 起点
- payment 系は settlement 系起点
- customer_count は ret_daily_settlement_summary から取得
- ディメンション名寄せは ETL 時に実施してもよい

## 17.3 Load

- INSERT ... ON DUPLICATE KEY UPDATE による UPSERT を必須とする
- 破壊的操作は禁止
- TRUNCATE / DROP は行わない
- 差分更新と再実行に耐える冪等設計にする

---

# 18. Laravel からの実行方針

Laravel / Filament 側では、Python ETL をラップするだけに留める。

## 18.1 Artisan コマンド

- `php artisan insights:generate-stats --mode=realtime`
- `php artisan insights:generate-stats --mode=daily`
- `php artisan insights:generate-stats --mode=full`

## 18.2 Scheduler

- 15分ごと: realtime
- 毎日締め後: daily

---

# 19. 表示性能方針

酒丸算盤の統計画面では、以下を厳守する。

1. ret_ テーブルを直接読まない
2. Fact を毎回 GROUP BY しない
3. Summary テーブルを直接読む
4. フィルタ済み一覧が 50ms 目標で返るようにする

つまり画面系は以下を直接読む構造にする。

- ins_daily_store_sales
- ins_daily_item_sales
- ins_daily_category_sales
- ins_daily_store_item_sales
- ins_hourly_store_sales
- ins_daily_payment_summary
- ins_monthly_store_sales
- ins_monthly_item_sales

---

# 20. インデックス方針

各 Summary テーブルは UPSERT 用のユニークキーと検索用インデックスを明示的に持つこと。

例:

## 20.1 ins_daily_store_sales

ユニーク:

- (business_date, ret_store_id)

検索:

- (ret_store_id, business_date)
- (business_date)
- (area, business_date) ※ area を持つ場合

## 20.2 ins_daily_item_sales

ユニーク:

- (business_date, item_code)

検索:

- (item_code, business_date)
- (category_code, business_date)
- (business_date)

## 20.3 ins_hourly_store_sales

ユニーク:

- (business_date, ret_store_id, time_slot)

検索:

- (ret_store_id, business_date)
- (business_date, time_slot)

## 20.4 ins_daily_payment_summary

ユニーク:

- (business_date, ret_store_id, payment_type)

検索:

- (ret_store_id, business_date)
- (payment_type, business_date)

## 20.5 ins_monthly_store_sales

ユニーク:

- (year_month, ret_store_id)

検索:

- (ret_store_id, year_month)
- (year_month)

---

# 21. Summary テーブルのフラット化方針

JOIN削減のため、Summary テーブルには必要なラベル類を冗長保持してよい。

例:

- store_name
- area
- item_name
- category_name
- payment_label

ただし、基準マスタは Dimension 側にも保持し、必要なら後で整合性確認できるようにする。

---

# 22. 実装時の禁止事項

1. ret_ テーブルの変更禁止
2. ret_ テーブルへの index 追加も原則禁止（必要なら別指示）
3. FK制約禁止
4. 画面から raw テーブル直接参照禁止
5. Fact と settlement の売上値を混在させること禁止
6. 破壊的再構築前提の ETL 設計禁止

---

# 23. AI に対する明示指示

以下を前提として設計・実装案を出すこと。

## 23.1 まず行うこと

1. ret_ テーブルの実カラム調査
2. ret_stores / ret_items / ret_item_categories の存在確認
3. settlement系から支払種別の正規化方針を整理
4. sales_amount / sales_qty / gross_profit の基準ソースを ret_daily_sales に固定

## 23.2 設計時の優先順位

1. 数字整合性
2. 粒度の統一
3. 冪等なETL
4. 高速表示
5. 将来拡張性

## 23.3 AI が出力すべきもの

- ins_ テーブル一覧
- 各テーブルのカラム定義
- 各テーブルの粒度
- raw → fact → aggregate のデータフロー
- Python ETL の処理単位
- UPSERTキー設計
- Filament 画面ごとの利用テーブル対応表
- 検算方針

---

# 24. この追加修正を反映した最終ゴール

最終的に、小売統計基盤は以下を満たすこと。

- ret_ raw データを安全に保持している
- ins_ 側に Fact / Dimension / Summary が構築されている
- 売上日報、エリア別売上、売上報告書、日計表、商品別売上、時間帯別売上、月次売上を高速表示できる
- 数字の基準が統一され、帳票間の不一致が起きにくい
- Python ETL により再計算・差分更新・日次更新ができる
- 将来のBI / AI分析の基盤としてそのまま拡張できる

---

# 25. AI への最終指示文

以下を厳守して設計・実装案を作成してください。

1. 小売統計基盤は **ret_ = raw / ins_ = analytics** の責務分離で設計すること。
2. 売上・数量・粗利の基準ソースは **ret_daily_sales** に固定すること。
3. settlement 系は支払分析・客数補助として使い、売上基準値として使わないこと。
4. Fact テーブルは **ins_sales_fact（日 × 店舗 × 商品）** を中心に設計すること。
5. 時間帯分析は **ins_hourly_sales_fact** または **ins_hourly_store_sales** を別系統で設計すること。
6. 表示は必ず Daily / Monthly Summary テーブルを直接参照し、Fact の都度集計を避けること。
7. 帳票対象は **エリア別売上日報、売上報告書、日計表、商品別売上、時間帯別売上、月次売上** とし、**在庫金額は除外**すること。
8. 酒丸特有の `sales_ret_store_id` / `shipping_ret_store_id` を保持し、将来の分析で切り分け可能にすること。
9. ETL は Python + UPSERT で冪等に実装すること。
10. 結果として、酒丸算盤で高速に表示できる小売統計基盤の仕様書・DDL方針・ETL方針・画面対応方針を出力すること。

