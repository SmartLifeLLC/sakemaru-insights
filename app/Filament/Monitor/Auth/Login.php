<?php

namespace App\Filament\Monitor\Auth;

use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\IconPosition;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 酒丸守人（もりびと）のログイン画面。認証処理は Filament 標準のまま、見た目だけを差し替える。
 * 画面: resources/views/monitor/auth/{layout,login}.blade.php、CSS: resources/css/monitor/login.css。
 * app/Filament/Monitor/Pages の外に置く（discoverPages の対象にしない）。
 */
class Login extends BaseLogin
{
    protected string $view = 'monitor.auth.login';

    protected static string $layout = 'monitor.auth.layout';

    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return 'おかえりなさい';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getSubheading();
        }

        return 'アカウント情報を入力してログインしてください';
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->hiddenLabel()
            ->placeholder('user@example.com')
            ->prefixIcon('heroicon-o-envelope')
            ->inlinePrefix();
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->hiddenLabel()
            ->placeholder('パスワード')
            ->prefixIcon('heroicon-o-lock-closed')
            ->inlinePrefix()
            ->inlineSuffix();
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->label('ログイン状態を保持');
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->label('ログイン')
            ->icon('heroicon-m-arrow-right')
            ->iconPosition(IconPosition::After)
            ->size('lg');
    }
}
