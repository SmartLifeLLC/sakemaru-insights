# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 絶対禁止事項（CRITICAL）

### データベース破壊コマンドの禁止

```bash
php artisan migrate:fresh      # 禁止
php artisan migrate:refresh    # 禁止
php artisan migrate:reset      # 禁止
php artisan db:wipe            # 禁止
```

---

## 重要: UIデザイン仕様（プロジェクト横断共通）

以下のデザイン仕様書は酒丸シリーズ全プロジェクト共通。該当UIの作成・修正時に必ず参照すること。

1. **モーダルデザイン仕様**: `~/.claude/design-knowledge/modal-design.md`
   - ヘッダー紺色（#1e293b）、ボタン右寄せ、実行ボタン赤（danger）
   - キャンセルは「〜せず閉じる」形式
   - CSSクラス: `incoming-detail-modal`（theme.css で定義済み）

2. **メガメニュー仕様**: `~/.claude/design-knowledge/mega-menu.md`
   - ヘッダー `bg-slate-800`、高さ 2.5rem、z-[35]
   - 動的カラムレイアウト（1〜3列）、Split View 連携

3. **テーブルタブ表示仕様**: `~/.claude/design-knowledge/table-tabs.md`（プロジェクト横断共通）
   - 4パターン: getTabs() / PresetView / Form Schema Tabs / Sub-Navigation Tabs
   - パターン選択ガイド・実装例・動的タブ生成・キャッシュ戦略
   - テーブル固定高さ + 内部スクロール + sticky thead

4. **ページスクロール制御仕様**: `~/.claude/design-knowledge/page-scroll-control.md`
   - HTML overflow 制御、sticky カラム（右固定/左固定）
   - Split View（左右分割パネル + ドラッグリサイズ）

5. **テーブルコンパクトデザイン仕様**: `~/.claude/design-knowledge/table-compact-design.md`（プロジェクト横断共通）
   - 行コンパクト化、ページヘッダー余白、sticky-actions右固定、ストライプ行
   - TextInputColumn幅固定、トップバー高さ調整

### Filament 4 の注意事項

```php
use Filament\Schemas\Components\Section;      // NOT Filament\Forms\Components\Section
use Filament\Schemas\Components\Grid;         // NOT Filament\Infolists\Components\Grid
use Filament\Actions\Action;                  // NOT Filament\Tables\Actions\Action
```

---

## Project Overview

Sakemaru Insights (酒丸算盤) - Laravel 12 + Filament 4 application for data analytics and visualization.

**Tech Stack:**
- **Laravel 12** (PHP 8.2+)
- **Filament 4** (Admin Panel Framework)
- **Livewire 3** (Reactive components)
- **Tailwind CSS 4** (Styling, custom Washi & Sumi Design System)
- **Alpine.js** (Client-side interactivity)

## Development Commands

```bash
composer dev        # Development server
php artisan test    # Run tests
./vendor/bin/pint   # Code formatter
npm run build       # Production assets
npm run dev         # Dev server (HMR)
```
