# Work Plan: insights-ui-enhancement

- **ID**: insights-ui-enhancement
- **作成日**: 2026-03-09
- **最終更新**: 2026-03-09
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-insights/storage/specifications/design-update/20260309-insights-ui-enhancement/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260309-insights-ui-enhancement-boot.md）
2. 20260309-insights-ui-enhancement-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

Insights UIの改善: ヘッダーコンパクト化・テーブル情報密度向上・店舗別売上地図バブルチャート追加。

**仕様修正**: ユーザーフィードバックにより以下を変更:
- MegaMenuのフラットリンク化は取り消し → ドロップダウンメニューを維持
- 「ヘッダー」はトップバーではなくコンテンツヘッダー（.fi-header-heading）の高さ縮小

## 重要な設計制約

- FK（外部キー制約）は使用しない（プロジェクト規約）
- `php artisan migrate:refresh` は禁止（本番データ保護）
- ins_dim_stores への latitude/longitude 追加は nullable
- Leaflet.js はCDNから読み込み（npm不要）
- 緯度経度NULLの店舗は地図から除外（whereNotNull）

## 対象ファイル

### 新規作成
- `app/Livewire/StoreSalesMap.php` — 実装済み
- `resources/views/livewire/store-sales-map.blade.php` — 実装済み
- `database/migrations/2026_03_09_100001_add_geo_columns_to_dim_store.php` — 実装済み

### 既存変更
- `resources/views/livewire/mega-menu.blade.php` — ドロップダウンメニュー復元（ユーザー手動修正）
- `resources/css/filament/admin/theme.css` — コンテンツヘッダー縮小CSS追加 + topbar 2.5rem
- 各Filament Resources — ページネーション100 実装済み
- `app/Filament/Pages/AreaDailySalesReport.php` — 地図セクション組み込み済み
- `resources/views/filament/pages/area-daily-sales-report.blade.php` — StoreSalesMap配置済み

### 参照のみ（変更禁止）
- `app/Models/Insights/DailyStoreSales.php` — 地図データのクエリ元

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: 現状確認・差分分析 | 完了 | 2026-03-09 | 全Phase実装済みを確認 |
| P1: ナビ簡素化 検証 | キャンセル | 2026-03-09 | ユーザー指示: メニューは元に戻す（ドロップダウン維持） |
| P2: テーブル密度 検証 | 完了 | 2026-03-09 | 0.7rem/0.65rem・pagination100・alignEnd |
| P3: 地図バブルチャート 実装/完成 | 完了 | 2026-03-09 | Leaflet・circleMarker・tooltip・filterDate連動 |
| P4: 統合テスト・微調整 | 完了 | 2026-03-09 | コンテンツヘッダー縮小CSS追加・ビルド確認 |

---

## 作業中コンテキスト

### 既存実装状況（P0完了）
- MegaMenu: ドロップダウンメニュー（ユーザー手動でh-10・FontAwesome・グループ化パネルに修正）
- トップバー高さ: 2.5rem（ユーザー修正）
- コンテンツヘッダー: .fi-header pt-1 pb-0 / .fi-header-heading text-base leading-tight
- テーブルCSS: 0.7rem本文/0.65remヘッダー/1px 2px padding/stripe済み
- StoreSalesMap コンポーネント: 完全実装済み（Leaflet CDN v1.9.4）
- ETL lat/lng 転記: Python ETLに委譲

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

### P0: 現状確認・差分分析
- 完了日: 2026-03-09
- 実績:
  - 全対象ファイルを確認、Phase 2-3 は既に実装済み

### P1: ナビ簡素化 検証
- 完了日: 2026-03-09 (キャンセル)
- 実績:
  - ユーザーフィードバック: 「メニューは元に戻す」
  - ドロップダウンMegaMenuを維持（ユーザーが手動で修正）

### P2: テーブル密度 検証
- 完了日: 2026-03-09
- 実績:
  - theme.css: .fi-ta-text 0.7rem、.fi-ta-header-cell 0.65rem、padding 1px 2px
  - 全リソース: defaultPaginationPageOption(100)、paginationPageOptions([100,500,1000,1500,2000])

### P3: 地図バブルチャート 実装/完成
- 完了日: 2026-03-09
- 実績:
  - StoreSalesMap.php + store-sales-map.blade.php 完全実装
  - Leaflet CDN v1.9.4、circleMarker(#4f46e5/#3730a3)、tooltip

### P4: 統合テスト・微調整
- 完了日: 2026-03-09
- 実績:
  - コンテンツヘッダー縮小CSS追加: .fi-header-heading text-base leading-tight
  - MegaMenuドロップダウン復元（ユーザー手動修正を反映）
  - Vite ビルド成功
