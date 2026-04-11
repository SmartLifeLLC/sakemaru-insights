# Insights UI Enhancement - メニュー項目削除・テーブル情報密度向上・地図バブルチャート

- **作成日**: 2026-03-09
- **ステータス**: 確定
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-insights/storage/specifications/design-update

## 背景・目的

現在のInsights UIには以下の課題がある:

1. **トップバーの「Menu」項目が不要** — MegaMenu自体は残すが、ドロップダウンの「Menu」ボタン/タブが不要。Dashboardへはロゴクリックで遷移できる。各ページへの直リンクだけで十分
2. **ヘッダーが大きすぎる** — 上部ヘッダー領域が画面を占有し、テーブル表示領域が狭い
3. **テーブルの情報密度が不足** — より多くのデータを一画面に表示する必要がある
4. **店舗別売上の地理的可視化がない** — POS実店舗の売上を地図上にバブルチャートで表示したい（参考画像: 店舗別売上サンプル.png）

## 現状の実装

### ナビゲーション構造
- **MegaMenu**: `app/Livewire/MegaMenu.php` + `resources/views/livewire/mega-menu.blade.php`
- **レイアウト**: `resources/views/vendor/filament-panels/components/layout/index.blade.php` でFilamentデフォルトのtopbarをMegaMenuに差し替え
- **AdminPanelProvider**: `->topNavigation()` + `->maxContentWidth('full')` + `->breadcrumbs(false)`
- MegaMenu header高さ: `h-10` (40px) + ドロップダウンパネル
- 現在のメニュー構造: `$menuStructure` から各ナビゲーショングループをドロップダウンボタンとして表示

### テーブル定義
全リソースが `統計分析` ナビゲーショングループに所属:
1. AreaDailySalesReport (エリア別売上日報) — Custom Page with KPI cards + Chart.js
2. SalesReportResource (売上報告書)
3. DailySettlementResource (日計表)
4. DailyItemSalesResource (商品別売上)
5. HourlyStoreSalesResource (時間帯別売上)
6. MonthlyReportResource (月次売上レポート)

### テーマCSS
`resources/css/filament/admin/theme.css` にコンパクトテーブル用CSS適用済み（前回のUI統一化で追加）

### チャートライブラリ
- Chart.js (CDN) — エリア別売上日報で使用中
- Alpine.js — Filament/Livewire標準同梱

### 緯度経度データ
- **warehouses テーブル**: `latitude`, `longitude` カラムあり。全30倉庫中27倉庫にデータあり（3件NULL: 輸入課、営業部石川系）
- **POS実店舗の判定**: `ret_stores.pos_store_code IS NOT NULL` でフィルタ
- **倉庫と店舗のリンク**: `ret_stores.code = warehouses.code`

## 変更内容

### 概要

MegaMenuからドロップダウン「Menu」ボタンを削除し、各ページへの直リンクのみにする。ヘッダー高さを最小化。テーブルの情報密度を向上。AreaDailySalesReport ページ内に店舗別売上の地図バブルチャート（Leaflet.js）を追加する。

---

### Phase 1: ナビゲーション簡素化 & ヘッダーコンパクト化

#### 1-1. MegaMenuの「Menu」ドロップダウン削除

MegaMenu自体は維持するが、ドロップダウン展開式の「Menu」ボタン/タブを削除し、各ページへのフラットな直リンク表示に変更する。

**変更対象:** `resources/views/livewire/mega-menu.blade.php`

**現状:**
```html
<!-- Desktop Nav Items -->
<nav class="flex items-center gap-1">
    @forelse($menuStructure as $tab)
        <button type="button" @click="openTab = ...">
            {{ $tab['label'] }}  <!-- "統計分析" 等のドロップダウンボタン -->
            <i class="fa-solid fa-chevron-down"></i>
        </button>
    @endforelse
</nav>
```

**変更後:** ドロップダウンボタンを廃止し、各ページへの直リンクに:
```html
<!-- Page Direct Links -->
<nav class="flex items-center gap-1">
    @foreach($flatLinks as $link)
        <a href="{{ $link['url'] }}"
           class="px-3 py-1 text-sm font-medium text-slate-200 hover:text-white hover:bg-slate-700 rounded-md transition-colors {{ $link['isActive'] ? 'text-white bg-slate-700' : '' }}">
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
```

**変更対象:** `app/Livewire/MegaMenu.php`

`$menuStructure` のネストされたグループ構造をフラットな `$flatLinks` 配列に変換するメソッドを追加:
```php
public function getFlatLinksProperty(): array
{
    $links = [];
    foreach ($this->menuStructure as $tab) {
        foreach ($tab['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $links[] = $item;
            }
        }
    }
    return $links;
}
```

同時にドロップダウンパネル（`<!-- Mega Menu Dropdowns -->` セクション）を削除。

#### 1-2. ヘッダー高さの最小化

**変更対象:** `resources/css/filament/admin/theme.css`

```css
/* トップバーをさらに縮小 */
.fi-topbar {
    height: 2rem !important;        /* 2.5rem → 2rem */
    min-height: 2rem !important;
}
.fi-topbar > nav {
    height: 2rem !important;
    min-height: 2rem !important;
}
```

**変更対象:** `resources/views/livewire/mega-menu.blade.php`

MegaMenu header の高さも縮小:
```html
<div class="flex items-center justify-between h-8">  <!-- h-10 → h-8 -->
```

---

### Phase 2: テーブル情報密度の向上

#### 2-1. フォントサイズ縮小

**変更対象:** `resources/css/filament/admin/theme.css`

```css
/* テーブルセル内テキストを縮小 */
.fi-ta-text, .fi-ta-text-item {
    font-size: 0.7rem !important;
    line-height: 1.1 !important;
}
.fi-ta-header-cell {
    font-size: 0.65rem !important;
}
```

#### 2-2. ページネーション設定

全テーブルのデフォルトページネーションを大量表示向けに:

```php
->defaultPaginationPageOption(100)
->paginationPageOptions([100, 500, 1000, 1500, 2000])
```

#### 2-3. カラム幅の最適化

各リソースのテーブル定義で、不要な余白を削減:
- 数値カラムに `->alignEnd()` を適用
- 固定幅 `->width('80px')` 等で余計な拡張を防ぐ
- `->size(TextColumn\TextColumnSize::ExtraSmall)` を適用

---

### Phase 3: 店舗別売上 地図バブルチャート

#### 3-1. 概要

POS実店舗（`ret_stores.pos_store_code IS NOT NULL`）の売上データを地図上にバブル（円マーカー）で表示。参考画像のように郵便番号/地域ベースで売上規模をバブルサイズで可視化する。

- **バブルサイズ** = 売上金額に比例（平方根スケール）
- **バブル色** = Indigo系カラー（Insightsテーマカラー）単色
- **ベースマップ** = OpenStreetMap (Leaflet.js)
- **インタラクション** = ホバーで店舗名・売上額・客数のツールチップ表示
- **配置先** = AreaDailySalesReport ページ内（同一ページにセクションとして追加）
- **初期表示** = 福井県中心 (lat: 36.06, lng: 136.22, zoom: 10)

#### 3-2. データ要件

**ins_dim_stores に緯度経度カラムを追加:**
- `latitude` DECIMAL(10,7) NULLABLE
- `longitude` DECIMAL(10,7) NULLABLE
- `postal_code` VARCHAR(10) NULLABLE
- `has_pos` TINYINT(1) DEFAULT 0 — POS実店舗フラグ

ETL（GenerateStatsCommand）で warehouses テーブルから転記:
```sql
-- リンク方法
ret_stores.code = warehouses.code
-- POS実店舗判定
ret_stores.pos_store_code IS NOT NULL
```

#### 3-3. UI実装

**新規 Livewire コンポーネント:**
- `app/Livewire/StoreSalesMap.php`
- `resources/views/livewire/store-sales-map.blade.php`

**配置先:** AreaDailySalesReport ページ内にセクションとして追加

**機能:**
- Leaflet.js (CDN v1.9.4) で地図描画
- Alpine.js でインタラクション制御
- 日付フィルターはAreaDailySalesReportの既存フィルターと連動
- POS実店舗のみ表示（`has_pos = 1`）
- バブルサイズの自動スケーリング（平方根正規化）

**Blade テンプレート構造:**
```html
<div x-data="storeSalesMap(@js($mapData))" class="w-full h-[500px] rounded-lg border border-gray-200 overflow-hidden">
    <div id="store-sales-map" class="w-full h-full"></div>
</div>
```

**Alpine.js コンポーネント:**
```javascript
Alpine.data('storeSalesMap', (mapData) => ({
    map: null,
    markers: [],
    init() {
        // Leaflet.js 動的ロード（CDN）
        // OpenStreetMap タイル設定
        // 初期表示: 福井県中心 (36.06, 136.22, zoom: 10)
        // mapData からバブルマーカー生成
        // L.circleMarker() でサイズ・色を設定
        // ツールチップ: 店舗名 / 売上額 / 客数
    },
    calcRadius(amount, maxAmount) {
        return Math.max(5, Math.sqrt(amount / maxAmount) * 30);
    }
}));
```

**マーカー色:**
| 要素 | 色 | fillOpacity |
|------|-------|------------|
| POS実店舗バブル | #4f46e5 (indigo-600) | 0.6 |
| バブル枠線 | #3730a3 (indigo-800) | 0.8 |

#### 3-4. サーバー側データ

`StoreSalesMap.php` Livewire コンポーネントの `getMapData()`:

```php
public function getMapData(): array
{
    return DailyStoreSales::query()
        ->where('business_date', $this->selectedDate)
        ->join('ins_dim_stores', 'ins_dim_stores.id', '=', 'ins_daily_store_sales.store_id')
        ->where('ins_dim_stores.has_pos', 1)
        ->whereNotNull('ins_dim_stores.latitude')
        ->whereNotNull('ins_dim_stores.longitude')
        ->select([
            'ins_dim_stores.store_name',
            'ins_dim_stores.latitude',
            'ins_dim_stores.longitude',
            'ins_dim_stores.postal_code',
            'ins_daily_store_sales.sales_amount',
            'ins_daily_store_sales.customer_count',
        ])
        ->get()
        ->map(fn ($row) => [
            'name' => $row->store_name,
            'lat' => (float) $row->latitude,
            'lng' => (float) $row->longitude,
            'postal_code' => $row->postal_code,
            'sales' => (int) $row->sales_amount,
            'customers' => (int) $row->customer_count,
        ])
        ->toArray();
}
```

---

## 影響範囲

### 直接変更
- MegaMenu のドロップダウンMenu削除 → ナビゲーション表示がフラットリンクに変更
- ヘッダー高さ変更 → 全ページのレイアウト影響（コンテンツ領域拡大）
- テーブルCSS変更 → 全テーブルのフォントサイズ・余白変更
- AreaDailySalesReport に地図セクション追加

### 間接影響
- 印刷レイアウト — フォントサイズ変更の影響確認が必要
- ETL処理 — ins_dim_stores への lat/lng/has_pos カラム追加と転記ロジック

## 制約

- FK（外部キー制約）は使用しない（プロジェクト規約）
- `php artisan migrate:refresh` は禁止（本番データ保護）
- INSERTの回帰テスト（ETL処理）は必要に応じて実施
- ins_dim_stores への latitude/longitude 追加時は既存データの互換性を維持（nullable）
- Leaflet.js はCDNから読み込み（npm不要）
- 緯度経度NULLチェック必須: `whereNotNull` でフィルタ

## 対象ファイル

### 新規作成
- `app/Livewire/StoreSalesMap.php` — 地図バブルチャートコンポーネント
- `resources/views/livewire/store-sales-map.blade.php` — 地図テンプレート
- `database/migrations/XXXX_add_geo_columns_to_ins_dim_stores.php` — 緯度経度・has_posカラム追加

### 既存変更
- `app/Livewire/MegaMenu.php` — `$flatLinks` プロパティ追加
- `resources/views/livewire/mega-menu.blade.php` — ドロップダウン → フラットリンク化、高さ縮小
- `resources/css/filament/admin/theme.css` — ヘッダー縮小 + テーブルフォント縮小
- `app/Filament/Resources/SalesReportResource.php` — テーブル情報密度向上
- `app/Filament/Resources/DailySettlementResource.php` — 同上
- `app/Filament/Resources/DailyItemSalesResource.php` — 同上
- `app/Filament/Resources/HourlyStoreSalesResource.php` — 同上
- `app/Filament/Resources/MonthlyReportResource.php` — 同上
- `app/Filament/Pages/AreaDailySalesReport.php` — 地図セクション追加
- `resources/views/filament/pages/area-daily-sales-report.blade.php` — 地図コンポーネント配置
- `app/Console/Commands/GenerateStatsCommand.php` — ETLで latitude/longitude/has_pos を転記
- `app/Models/Insights/DimStore.php` — 緯度経度・has_posカラム定義追加

### 参照のみ
- `app/Models/Insights/DailyStoreSales.php` — 地図データのクエリ元
