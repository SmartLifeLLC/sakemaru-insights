# モーダルデザインナレッジ (sakemaru-insights)

本プロジェクトのモーダルデザイン基準。新規モーダル作成時は必ず参照すること。
リファレンス実装: sakemaru-wms

---

## 1. CSS: モーダル外枠

テーマCSS: `resources/css/filament/admin/theme.css`

**注意**: 既存の `.modal-split-left`, `.modal-info-card` 等のクラスは旧デザイン。新規モーダルでは以下を使用する。

新しいモーダルを作る場合、theme.cssに以下を追加:

```css
/* {機能名}モーダル */
.{modal-class} .fi-modal-header {
    background-color: #1e293b !important;
    border-radius: 0.75rem 0.75rem 0 0;
    padding: 0.75rem 1rem;
}
.{modal-class} .fi-modal-header .fi-modal-heading {
    color: #ffffff !important;
}
.{modal-class} .fi-modal-header .fi-modal-description {
    color: #94a3b8 !important;
}
.{modal-class} .fi-modal-header .fi-modal-close-btn {
    color: #ffffff !important;
}
.{modal-class} .fi-modal-header .fi-modal-close-btn:hover {
    color: #cbd5e1 !important;
}
.{modal-class} .fi-modal-footer {
    justify-content: flex-end !important;
}
.{modal-class} .fi-modal-footer > * {
    justify-content: flex-end !important;
}
```

PHP側:
```php
Action::make('myAction')
    ->modalWidth('6xl')
    ->extraModalWindowAttributes(['class' => '{modal-class}'])
    ->schema([...]);  // Filament 4: schema() を使う（form() ではない）
```

---

## 2. フォームフィールド: ViewField パターン

Filament標準の `Select`, `DatePicker`, `CheckboxList` の代わりに `ViewField` + Alpine.js を使用する。

### インポート

```php
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
```

### 共通ルール

- Blade配置先: `resources/views/filament/forms/components/`（新規作成が必要）
- state バインド: `$wire.entangle('{{ $getStatePath() }}').live` — `.live` 必須
- ラッパー: `<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">`
- 全角半角対応: JavaScript `normalize('NFKC')`
- ダークモード: 全要素に `dark:` クラス必須

### コンポーネントテンプレート

初回実装時、以下のファイルを sakemaru-wms からコピーして配置:

| コンポーネント | コピー元 (sakemaru-wms) | コピー先 |
|---------------|------------------------|---------|
| Searchable Select | `resources/views/filament/forms/components/warehouse-select.blade.php` | 同パスに配置し変数名を汎用化 |
| Date Input | `resources/views/filament/forms/components/date-input.blade.php` | そのままコピー |
| Checkbox Grid | `resources/views/filament/forms/components/checkbox-grid.blade.php` | そのままコピー |

---

## 3. レイアウト

```php
->schema([
    Grid::make(2)->schema([...]),  // 横並び
])
```

---

## 4. カラーパレット

| 用途 | Light | Dark |
|------|-------|------|
| 背景メイン | `bg-white` | `dark:bg-gray-900` |
| 背景サブ | `bg-slate-50` | `dark:bg-gray-800` |
| ボーダー | `border-slate-200` | `dark:border-gray-700` |
| テキスト | `text-slate-800` | `dark:text-gray-200` |
| 選択背景 | `bg-blue-50` | `dark:bg-blue-900/30` |
| 選択ボーダー | `border-blue-400` | `dark:border-blue-500` |

---

## 5. 空状態・サマリー・警告

```html
<!-- 空状態 -->
<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500">
    <i class="fa fa-{icon} text-2xl mb-2"></i><p class="text-sm">{メッセージ}</p>
</div>

<!-- サマリーバー -->
<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">
    <span class="text-sm font-bold text-slate-700 dark:text-gray-200">合計</span>
    <span class="text-lg font-bold text-blue-600 dark:text-blue-400">{count}件</span>
</div>

<!-- 警告バー -->
<div class="flex items-center gap-2 p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800 text-yellow-700 dark:text-yellow-400 text-xs">
    <i class="fa fa-exclamation-triangle"></i><span>{メッセージ}</span>
</div>
```
