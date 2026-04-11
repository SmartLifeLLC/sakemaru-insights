cl# Insights UI Enhancement 作業計画

## 前提

- MegaMenu の `$flatLinks` によるフラットリンクナビゲーションは既に実装済み
- ヘッダー高さ `h-8` (32px)、topbar `2rem` CSS は適用済み
- テーブルコンパクトCSS（0.7rem、1px 2px padding）は適用済み
- 全リソースのページネーション 100 / [100,500,1000,1500,2000] は設定済み
- DimStore モデルに latitude, longitude, postal_code, has_pos カラム定義済み
- AreaDailySalesReport に StoreSalesMap セクションの参照あり
- ETL は Python スクリプト (`scripts/etl/generate_retail_stats.py`) に委譲

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | 現状確認・差分分析 | 既存実装と仕様の差分を洗い出す | 差分リスト作成、未実装項目の特定 |
| P1 | ナビ簡素化 検証 | MegaMenuのフラットリンク動作確認 | ドロップダウンなし、全ページ遷移可能 |
| P2 | テーブル密度 検証 | テーブル表示の情報密度確認 | フォント・余白・ページネーション仕様通り |
| P3 | 地図バブルチャート 実装/完成 | StoreSalesMap の完成度確認・未実装部分の実装 | 地図表示、バブル表示、ツールチップ動作 |
| P4 | 統合テスト・微調整 | 全体動作確認・最終調整 | 全機能正常動作、印刷レイアウト確認 |

---

## P0: 現状確認・差分分析

### 目的

仕様書の要件と既存実装の差分を正確に把握し、残作業を特定する。

### 調査手順

1. **StoreSalesMap コンポーネントの確認**
   ```bash
   cat app/Livewire/StoreSalesMap.php
   cat resources/views/livewire/store-sales-map.blade.php
   ```
   - Leaflet.js の読み込み方法
   - バブルマーカーの実装状況
   - ツールチップの表示内容
   - 日付フィルター連動の実装状況

2. **AreaDailySalesReport への組み込み確認**
   - StoreSalesMap がどのように配置されているか
   - 日付フィルターの連動方法

3. **ETL の lat/lng 転記ロジック確認**
   ```bash
   cat scripts/etl/generate_retail_stats.py | grep -A 20 "latitude\|longitude\|has_pos"
   ```

4. **マイグレーション確認**
   ```bash
   ls database/migrations/*geo*ins_dim*
   ls database/migrations/*dim_store*
   ```

### 完了条件

- 未実装項目のリストが作成されている
- boot.md の「作業中コンテキスト」に現状が記録されている

---

## P1: ナビ簡素化 検証

### 目的

MegaMenu のドロップダウン「Menu」ボタンが削除され、フラットリンクのみになっていることを確認する。

### 検証手順

1. `resources/views/livewire/mega-menu.blade.php` にドロップダウンパネル（`<!-- Mega Menu Dropdowns -->` セクション）が残っていないか確認
2. `MegaMenu.php` で `openTab` Alpine.js 変数やドロップダウン関連ロジックが残っていないか確認
3. ヘッダー高さが `h-8` (32px) であること確認
4. ロゴクリックでDashboardに遷移すること確認
5. 全ページ（6つのリソース/ページ）への直リンクが表示されていること確認

### 修正方針（差分がある場合）

- ドロップダウン関連の残骸コードを削除
- フラットリンクのスタイル調整（`px-3 py-1 text-sm` → 仕様通り）

### 修正対象ファイル

- `app/Livewire/MegaMenu.php`
- `resources/views/livewire/mega-menu.blade.php`
- `resources/css/filament/admin/theme.css`

### 完了条件

- ドロップダウンUI要素なし
- 全ページへの直リンクが機能
- ヘッダー高さが仕様通り

---

## P2: テーブル密度 検証

### 目的

テーブルのフォントサイズ・余白・ページネーション設定が仕様通りであることを確認する。

### 検証手順

1. **CSS確認**: `theme.css` に以下のルールがあること
   - `.fi-ta-text, .fi-ta-text-item`: `font-size: 0.7rem`, `line-height: 1.1`
   - `.fi-ta-header-cell`: `font-size: 0.65rem`

2. **各リソースのテーブル設定確認**:
   - `defaultPaginationPageOption(100)`
   - `paginationPageOptions([100, 500, 1000, 1500, 2000])`
   - 数値カラムに `->alignEnd()`
   - `->size(TextColumn\TextColumnSize::ExtraSmall)` の適用状況

3. **カラム幅最適化の確認**:
   - 固定幅 `->width('80px')` 等の設定有無
   - 不要な余白がないか

### 修正方針（差分がある場合）

- 未適用の `->size(ExtraSmall)` を追加
- 固定幅が未設定のカラムに適切な幅を設定

### 修正対象ファイル

- `resources/css/filament/admin/theme.css`
- `app/Filament/Resources/SalesReportResource.php`
- `app/Filament/Resources/DailySettlementResource.php`
- `app/Filament/Resources/DailyItemSalesResource.php`
- `app/Filament/Resources/HourlyStoreSalesResource.php`
- `app/Filament/Resources/MonthlyReportResource.php`

### 完了条件

- 全リソースのテーブルが仕様通りのフォントサイズ・余白・ページネーション
- 数値カラムが右寄せ

---

## P3: 地図バブルチャート 実装/完成

### 目的

POS実店舗の売上データをLeaflet.js地図上にバブルチャートで表示する機能を完成させる。

### 調査手順（P0 の結果に基づく）

1. StoreSalesMap.php の実装確認
2. store-sales-map.blade.php の実装確認
3. Leaflet.js CDN読み込みの確認
4. Alpine.js `storeSalesMap` コンポーネントの確認

### 実装内容（未実装部分のみ）

#### 3-1. Livewire コンポーネント: `StoreSalesMap.php`

```php
// 必要なプロパティ
public string $selectedDate;

// getMapData(): DailyStoreSales + DimStore JOIN
// POS実店舗のみ (has_pos = 1)
// lat/lng NOT NULL フィルタ
// 返却: name, lat, lng, postal_code, sales, customers
```

#### 3-2. Blade テンプレート: `store-sales-map.blade.php`

```html
<div x-data="storeSalesMap(@js($mapData))" class="w-full h-[500px] rounded-lg border border-gray-200 overflow-hidden">
    <div id="store-sales-map" class="w-full h-full"></div>
</div>
```

#### 3-3. Alpine.js コンポーネント

- Leaflet.js CDN v1.9.4 動的ロード
- OpenStreetMap タイル
- 初期表示: 福井県中心 (lat: 36.06, lng: 136.22, zoom: 10)
- `L.circleMarker()` でバブル描画
- バブルサイズ: `Math.max(5, Math.sqrt(amount / maxAmount) * 30)`
- バブル色: `#4f46e5` (indigo-600), 枠線 `#3730a3` (indigo-800)
- fillOpacity: 0.6, 枠線 opacity: 0.8
- ツールチップ: 店舗名 / 売上額 / 客数

#### 3-4. AreaDailySalesReport との連携

- 日付フィルター連動: `$filterDate` を StoreSalesMap に渡す
- ページ内セクションとして配置

#### 3-5. ETL 確認

- Python ETL が warehouses テーブルから lat/lng を ins_dim_stores に転記していること
- `ret_stores.pos_store_code IS NOT NULL` で has_pos フラグを設定していること

### 修正対象ファイル

- `app/Livewire/StoreSalesMap.php` — 新規 or 修正
- `resources/views/livewire/store-sales-map.blade.php` — 新規 or 修正
- `app/Filament/Pages/AreaDailySalesReport.php` — 地図セクション配置確認
- `resources/views/filament/pages/area-daily-sales-report.blade.php` — 地図コンポーネント配置確認

### 完了条件

- ブラウザで AreaDailySalesReport ページを開くと地図が表示される
- POS実店舗のバブルマーカーが地図上に表示される
- バブルサイズが売上金額に比例（平方根スケール）
- ホバーで店舗名・売上額・客数のツールチップが表示される
- 日付フィルター変更で地図データが更新される
- 緯度経度NULLの店舗は表示されない

---

## P4: 統合テスト・微調整

### 目的

全Phase の変更が正しく統合されていることを確認し、最終調整を行う。

### 検証手順

1. **ナビゲーション確認**
   - 全6ページへの遷移テスト
   - ロゴクリックでDashboard遷移

2. **テーブル表示確認**
   - 各リソースページでテーブルの表示確認
   - ページネーション動作確認（100件表示、切り替え）

3. **地図バブルチャート確認**
   - AreaDailySalesReport ページで地図表示
   - バブルの表示・インタラクション
   - 日付切り替えでデータ更新

4. **レイアウト確認**
   - ヘッダーの高さが最小化されていること
   - テーブル領域が最大化されていること
   - レスポンシブ表示（タブレット・モバイル）

5. **印刷レイアウト確認**
   - フォントサイズ変更による印刷への影響

### 修正対象ファイル

- Phase 0-3 で変更した全ファイル

### 完了条件

- 全ページが正常に表示・動作する
- 回帰バグなし
- 印刷レイアウトに重大な問題なし

---

## 制約（厳守）

1. FK（外部キー制約）は使用しない
2. `php artisan migrate:refresh` は禁止
3. ins_dim_stores の latitude/longitude は nullable 必須
4. Leaflet.js は CDN から読み込み（npm 不使用）
5. 緯度経度 NULL の店舗は地図から除外
6. 既存のテーブルデータ・ETL ロジックを破壊しない
7. Python ETL スクリプトの修正は最小限に

## 全体完了条件

- MegaMenu がフラットリンクのみで動作
- ヘッダーが最小高さ（h-8 / 2rem）
- テーブルが高密度表示（0.7rem / 0.65rem header）
- 地図バブルチャートが AreaDailySalesReport に表示
- 全ページの遷移・表示が正常
