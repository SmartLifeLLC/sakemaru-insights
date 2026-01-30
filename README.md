# 酒丸算盤 (Sakemaru Insights)

経営分析・インサイトダッシュボードアプリケーション

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
