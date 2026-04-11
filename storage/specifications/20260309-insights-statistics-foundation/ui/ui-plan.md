# UIチーム 作業計画

## 前提

- DB設計チームが P2（Eloquentモデル）を完了済みであること
- `app/Models/Insights/` に15モデルが存在
- UIデザイン仕様: `../design-addition.html`, `../design-addition.md`
- 追加修正仕様: `../sakemaru_insights_prompt_addendum.md`
- Filament v4 + Livewire 3

---

## Phase 一覧

| # | Phase | 概要 | 主テーブル |
|---|-------|------|-----------|
| P5-1 | エリア別売上日報 | ダッシュボード + KPI + チャート + テーブル | daily_store_sales |
| P5-2 | 売上報告書 | 売上サマリ + 支払内訳 | daily_store_sales + daily_payment_summary |
| P5-3 | 日計表 | 支払種別一覧 | daily_payment_summary |
| P5-4 | 商品別売上 | 商品ランキング | daily_item_sales |
| P5-5 | 時間帯別売上 | 時間帯チャート + テーブル | hourly_store_sales |
| P5-6 | 月次売上レポート | 月次トレンド | monthly_store_sales + monthly_item_sales |

---

## UIデザイン方針（全帳票共通）

design-addition.html / design-addition.md に準拠:

- **カラーパレット**: indigo-600 アクセント、slate-50 背景
- **セマンティックカラー**: Blue=売上, Indigo=客数, Purple=単価, Amber=粗利, Emerald=達成
- **要素デザイン**: rounded-2xl、border-slate-200、控えめなドロップシャドウ
- **KPIカード**: SVGアイコン + 指標名 + メイン数値 + 補助情報（前年比等）
- **数値フォーマット**: ja-JP ロケール（3桁区切り、¥記号）
- **テーブル**: 検索バー付き、hover:bg-slate-50、粗利率プログレスバー、達成バッジ

---

## P5-1: エリア別売上日報（メインダッシュボード）

### 目的

全店舗の日次販売パフォーマンスを一目で把握できるダッシュボードを構築する。

### 画面構成

1. **KPIカード（4列グリッド）**
   - 当日売上高（前年比バッジ）← Blue
   - 当日客数（累計バッジ）← Indigo
   - 当日客単価 ← Purple
   - 当日粗利益（粗利率バッジ）← Amber

2. **チャートセクション（2列グリッド）**
   - 左: エリア別売上構成ドーナツチャート（嶺北/丹南/嶺南/坂井/その他）
   - 右: 店舗別売上比較棒グラフ（上位8店舗）

3. **店舗別詳細テーブル**
   - 検索バー付き
   - カラム: 店舗名、当日客数、客単価、当日売上、粗利率（プログレスバー）、目標達成バッジ
   - hover:bg-slate-50

4. **アクション**
   - CSVエクスポートボタン
   - レポート印刷ボタン
   - 日付フィルタ

### 主テーブル

- `ins_daily_store_sales`（直接参照、JOINなし）
- 必要に応じて `ins_dim_store`（エリアグルーピング用）

### 修正対象ファイル

- `app/Filament/Pages/AreaDailySalesReport.php`
- KPI Widget ファイル群
- チャート Widget ファイル群

### 完了条件

- KPIカードに正しい値が表示される
- ドーナツチャートとバーチャートが描画される
- 店舗テーブルでフィルタ・ソート・検索が動作する
- 日付切替でデータが更新される

---

## P5-2: 売上報告書

### 目的

店舗売上サマリと支払方法別内訳を表示する。

### 画面構成

- 日付・店舗フィルタ
- 売上サマリ: 売上、客数、粗利
- 支払方法別内訳テーブル: 現金 / クレジット / 電子マネー / 金券 / 売掛 / その他

### 主テーブル

- `ins_daily_store_sales`（売上サマリ）
- `ins_daily_payment_summary`（支払内訳）

### 修正対象ファイル

- `app/Filament/Resources/SalesReportResource.php`
- Pages サブディレクトリ

### 完了条件

- 売上サマリと支払内訳が正しく表示される
- 日付・店舗フィルタが動作する

---

## P5-3: 日計表

### 目的

支払方法別の売上集計を一覧表示する。

### 画面構成

- 日付・店舗フィルタ
- テーブル: 店舗名、支払種別、支払ラベル、金額、件数
- 支払種別でグルーピング or ソート

### 主テーブル

- `ins_daily_payment_summary`

### 修正対象ファイル

- `app/Filament/Resources/DailySettlementResource.php`
- Pages サブディレクトリ

### 完了条件

- 支払種別一覧が正しく表示される
- 日付・店舗フィルタが動作する

---

## P5-4: 商品別売上

### 目的

商品別売上ランキングと商品分析を表示する。

### 画面構成

- 日付・カテゴリフィルタ
- テーブル: 商品名、カテゴリ名、売上、数量、粗利
- ソート: 売上順（デフォルト）
- 必要に応じて ins_daily_store_item_sales で店舗別ドリルダウン

### 主テーブル

- `ins_daily_item_sales`
- `ins_daily_store_item_sales`（ドリルダウン時）

### 修正対象ファイル

- `app/Filament/Resources/DailyItemSalesResource.php`
- Pages サブディレクトリ

### 完了条件

- 商品一覧が売上順に表示される
- 日付・カテゴリフィルタが動作する

---

## P5-5: 時間帯別売上

### 目的

時間帯別の売上推移と来店ピークを分析する。

### 画面構成

- 日付・店舗フィルタ
- 時間帯別棒グラフ（横軸=時間帯、縦軸=売上）
- テーブル: 時間帯、売上、数量、粗利、客数

### 主テーブル

- `ins_hourly_store_sales`

### 修正対象ファイル

- `app/Filament/Resources/HourlyStoreSalesResource.php`
- Pages サブディレクトリ
- チャート Widget

### 完了条件

- 時間帯別チャートが描画される
- テーブルでフィルタ・ソートが動作する

---

## P5-6: 月次売上レポート

### 目的

月次推移と月別比較を表示する。

### 画面構成

- 年月・店舗フィルタ
- 店舗別月次テーブル: 店舗名、エリア、売上、数量、粗利、客数、客単価、粗利率
- 商品別月次テーブル: 商品名、カテゴリ、売上、数量、粗利
- 月次トレンドチャート（折れ線グラフ）

### 主テーブル

- `ins_monthly_store_sales`
- `ins_monthly_item_sales`

### 修正対象ファイル

- `app/Filament/Resources/MonthlyReportResource.php`
- Pages サブディレクトリ
- チャート Widget

### 完了条件

- 月次テーブルが正しく表示される
- トレンドチャートが描画される
- 年月・店舗フィルタが動作する

---

## 制約（厳守）

1. 画面は Summary テーブルを直接読む（Fact の都度集計禁止）
2. クエリ50ms以内
3. JOINなしで表示（フラット構造の恩恵を活かす）
4. 数値は ja-JP ロケールでフォーマット
5. design-addition.html のカラーパレット・レイアウトに準拠
6. 在庫金額レポートは対象外
