# 酒丸算盤 (Sakemaru Insights)

経営分析・インサイトダッシュボードアプリケーション

## 集計処理の停止

Insightsの集計処理は停止中です。15分・日次のスケジュール登録を解除し、
`insights:generate-stats` と Python ETL の全モード（`daily` / `realtime` / `full`）は
停止中のメッセージを表示して正常終了します。集計・UPSERTは行いません。
Pythonの集計関数を直接呼んでも、DB読み取り・書き込みには進みません。

停止対象はInsights専用のスケジュール・Artisanコマンド・Python集計処理に限定します。
汎用ローダー (`scripts/etl/etl/load.py`)、共有ビュー・フィルター表、共通マスター、
sakemaru-ai-core / sakemaru-trade / sakemaru-wms のコード・集計処理は変更しません。

環境変数による再有効化はありません。再開する場合は、この停止変更をコードレビューのうえ戻してください。
共有ビュー・フィルター表・既存の集計データ・画面は維持します。
本番の停止には両サーバへのコード反映が必要です。実行中の旧処理は中断せず、
毎分のcron起動と常駐queue workerの停止は別の運用作業です。

## Tech Stack

- **Laravel 12** (PHP 8.2+)
- **Filament 4** (Admin Panel Framework)
- **Livewire 3** (Reactive Components)
- **Tailwind CSS 4** (Styling)
- **Vite** (Asset Bundling)
- **MySQL** (Database)

## Architecture

### Database Connections

このプロジェクトは2つのデータベース接続を使用:

- **`mysql`**: Insightsテーブル用（`ins_` プレフィックス）
- **`sakemaru`**: 共有マスター/認証テーブル用（プレフィックスなし）

### Cross-Application Session Sharing

WMS・Trade等の他アプリケーションとセッションを共有:

- `SESSION_DOMAIN=.sakemaru.test` で全サブドメイン間で共有
- `SESSION_COOKIE=sakemaru_session` で統一Cookie名
- `SESSION_CONNECTION=sakemaru` でセッションテーブルを共有

## Setup

### Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL 8.0+

### Installation

```bash
# Clone repository
git clone <repository-url>
cd sakemaru-insights

# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Edit .env with your database credentials

# Build assets
npm run build

# Run migrations (if any)
php artisan migrate
```

### Development

```bash
# Start development server
php artisan serve

# Or use Valet
valet link insights.sakemaru
valet secure insights.sakemaru

# Asset watch mode
npm run dev
```

### Valet Configuration

Valetで動作させる場合:

```bash
cd /Users/jungsinyu/Projects/sakemaru-insights
valet link insights.sakemaru
valet secure insights.sakemaru
```

これにより `https://insights.sakemaru.test` でアクセス可能。

## URL

- **Local**: https://insights.sakemaru.test/admin

## Related Projects

- [sakemaru-wms](../sakemaru-wms) - Warehouse Management System
- [sakemaru-trade](../sakemaru-trade) - Trade Management System
