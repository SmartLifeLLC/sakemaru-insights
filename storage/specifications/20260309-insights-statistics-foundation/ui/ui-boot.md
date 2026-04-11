# Work Plan: UIチーム

- **ID**: insights-ui
- **作成日**: 2026-03-09
- **最終更新**: 2026-03-09
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-insights/storage/specifications/20260309-insights-statistics-foundation/ui/
- **管理者ファイル**: ../manager-boot.md

## 開始条件

**DB設計チームの P2（Eloquentモデル）が完了していること。**
開始前に以下を確認:
1. ../manager-boot.md の「共有コンテキスト > モデル完了情報」が記入済み
2. `app/Models/Insights/` に15モデルが存在
3. ins_ テーブルにデータが存在（Python ETL チームが実行済みだとベスト）

## セッション再開手順

1. このファイルを読む（ui-boot.md）
2. ui-plan.md を読む
3. 下記「進捗」テーブルで現在のPhaseを確認
4. ../manager-boot.md の「共有コンテキスト」から必要情報を取得
5. 未完了の最初のPhaseから作業再開

## 概要

6帳票（エリア別売上日報、売上報告書、日計表、商品別売上、時間帯別売上、月次売上）の Filament UI を構築する。

## 絶対禁止事項

- **`php artisan migrate:refresh` / `migrate:fresh` は絶対に実行禁止**
- キャッシュ削除: `php artisan cache:hard-clear`

## テスト環境

- URL: `https://insights.sakemaru.test/admin`
- ログイン: `admin@sakemaru.ai` / `1234567a`
- DB: `sakemaru_hana_prod` / User: `root` / Password: なし

## mega-menu 参照

メニュー構成は `mega-menu.blade.php` を参考にする（git commit `4247f69` に存在）。
- `git show 4247f69:resources/views/livewire/mega-menu.blade.php`
- `git show 4247f69:app/Livewire/MegaMenu.php`
- スタイル: slate-800 背景、indigo-600 アクセント、Alpine.js ドロップダウン

## 重要な設計制約

- **画面は Summary テーブルを直接読む**（Fact の都度集計は避ける）
- **クエリ50ms以内**
- **フラット構造の恩恵**: JOINなしで表示可能（store_name, area 等は Summary に冗長保持済み）
- **UIデザイン仕様**: design-addition.html / design-addition.md 準拠
- **在庫金額レポートは対象外**

## 対象ファイル

### 新規作成
- `app/Filament/Pages/AreaDailySalesReport.php`（エリア別売上日報）
- `app/Filament/Resources/SalesReportResource.php`（売上報告書）
- `app/Filament/Resources/DailySettlementResource.php`（日計表）
- `app/Filament/Resources/DailyItemSalesResource.php`（商品別売上）
- `app/Filament/Resources/HourlyStoreSalesResource.php`（時間帯別売上）
- `app/Filament/Resources/MonthlyReportResource.php`（月次売上レポート）
- 各 Resource の Pages サブディレクトリ
- Widget ファイル群（KPIカード、チャート）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P5-1: エリア別売上日報 | 完了 | 2026-03-09 | ダッシュボード + KPI + チャート + テーブル |
| P5-2: 売上報告書 | 完了 | 2026-03-09 | 売上サマリ + 支払内訳 + 店舗テーブル |
| P5-3: 日計表 | 完了 | 2026-03-09 | 支払種別一覧 + グルーピング |
| P5-4: 商品別売上 | 完了 | 2026-03-09 | 商品ランキング + カテゴリフィルタ |
| P5-5: 時間帯別売上 | 完了 | 2026-03-09 | 時間帯チャート + テーブル |
| P5-6: 月次売上レポート | 完了 | 2026-03-09 | 月次トレンド + 商品別 + 店舗別 |

---

## 作業中コンテキスト

### 使用モデル一覧（../manager-boot.md から取得）
- DailyStoreSales → エリア別日報、売上報告書
- DailyPaymentSummary → 売上報告書、日計表
- DailyItemSales → 商品別売上
- DailyStoreItemSales → 店舗別商品ドリルダウン
- HourlyStoreSales → 時間帯別売上
- MonthlyStoreSales → 月次レポート
- MonthlyItemSales → 月次レポート
- DimStore → エリア別日報（補助）

### Git ブランチ
- 作業ブランチ: feature/insights-ui
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P5-1: エリア別売上日報
- 完了日: 2026-03-09
- 成果物: app/Filament/Pages/AreaDailySalesReport.php, resources/views/filament/pages/area-daily-sales-report.blade.php
- 実績:
  - KPIカード4枚（売上/客数/客単価/粗利益）セマンティックカラー対応
  - エリア別ドーナツチャート（Chart.js）
  - 店舗別売上比較棒グラフ（上位8店舗）
  - 店舗別詳細テーブル（検索・ソート・粗利率カラーリング）
  - 日付フィルタ、印刷ボタン
  - Summary テーブル直接参照、JOINなし

### P5-2: 売上報告書
- 完了日: 2026-03-09
- 成果物: app/Filament/Resources/SalesReportResource.php, Pages/ListSalesReports.php, sales-report-list.blade.php
- 実績:
  - 売上サマリKPI（売上高/客数/粗利益）
  - 支払方法別内訳テーブル（DailyPaymentSummary）
  - 店舗別売上テーブル（日付・エリアフィルタ）

### P5-3: 日計表
- 完了日: 2026-03-09
- 成果物: app/Filament/Resources/DailySettlementResource.php, Pages/ListDailySettlements.php
- 実績:
  - 支払種別一覧テーブル（バッジ付き）
  - 日付・店舗・支払種別フィルタ
  - 支払種別/店舗名グルーピング

### P5-4: 商品別売上
- 完了日: 2026-03-09
- 成果物: app/Filament/Resources/DailyItemSalesResource.php, Pages/ListDailyItemSales.php
- 実績:
  - 商品売上ランキングテーブル（売上順デフォルト）
  - 日付・カテゴリフィルタ（カテゴリ検索可能）
  - カテゴリバッジ表示

### P5-5: 時間帯別売上
- 完了日: 2026-03-09
- 成果物: app/Filament/Resources/HourlyStoreSalesResource.php, Pages/ListHourlyStoreSales.php, hourly-store-sales-list.blade.php
- 実績:
  - 時間帯別売上+客数複合チャート（棒グラフ+折れ線）
  - 時間帯別詳細テーブル
  - 日付・店舗フィルタ

### P5-6: 月次売上レポート
- 完了日: 2026-03-09
- 成果物: app/Filament/Resources/MonthlyReportResource.php, Pages/ListMonthlyReports.php, monthly-report-list.blade.php
- 実績:
  - 月次売上トレンド折れ線チャート（売上+粗利益）
  - 店舗別月次サマリテーブル（年月/エリア/店舗フィルタ）
  - 商品別月次売上上位20テーブル
- **完了時アクション**: manager-boot.md のチーム進捗を更新
