<?php

namespace App\Console\Commands\Monitoring;

use App\Models\Monitoring\Contact;
use App\Monitoring\Services\NotificationTestService;
use Illuminate\Console\Command;

class NotifyTestCommand extends Command
{
    protected $signature = 'monitor:notify-test {--route=slack : slack / mail / phone / smtp_connection / twilio_connection} {--to= : 電話のテスト発信先（担当者名簿の ID または名前）}';

    protected $description = '運用監視: 通知経路のテスト（Slack は通知待ちへ、メール・電話は即時。結果は監査に残る）';

    public function handle(NotificationTestService $tests): int
    {
        $route = (string) $this->option('route');
        $contactId = null;
        if ($route === 'phone') {
            $to = (string) $this->option('to');
            $c = ctype_digit($to) ? Contact::query()->find((int) $to) : Contact::query()->where('name', $to)->first();
            if (! $c) {
                $this->error('--to に担当者名簿の ID または名前を指定してください。');

                return self::FAILURE;
            }
            $contactId = (int) $c->id;
        }
        try {
            $r = $tests->run($route, ['id' => null, 'name' => 'CLI notify-test'], $contactId);
        } catch (\InvalidArgumentException) {
            $this->error('--route は slack / mail / phone / smtp_connection / twilio_connection のいずれかです。');

            return self::FAILURE;
        }
        $r['ok'] ? $this->info($r['message']) : $this->error($r['message']);

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
