<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Monitoring\CallAttempt;
use App\Monitoring\Notify\PhoneNotifier;
use App\Monitoring\Notify\TwilioClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twilio からの呼び出し（v2 第7.2 / V-154）。全て X-Twilio-Signature を検証し、token は発信 1 回ごと・30 分で失効。
 *  POST /internal/monitor/voice/{token}         TwiML（読み上げ＋1 の入力待ち）
 *  POST /internal/monitor/voice/{token}/gather  入力（1 = 確認）
 *  POST /internal/monitor/voice/{token}/status  StatusCallback
 */
class VoiceController extends Controller
{
    public function __construct(private readonly PhoneNotifier $phone, private readonly TwilioClient $twilio) {}

    private function resolve(Request $request, string $token, string $suffix): CallAttempt|Response
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return $this->deny(404);
        }
        $url = $this->phone->publicBase().'/internal/monitor/voice/'.$token.$suffix;
        if (! $this->twilio->validSignature($url, $request->post(), (string) $request->header('X-Twilio-Signature', ''))) {
            return $this->deny(403);
        }
        $attempt = CallAttempt::query()->where('token_hash', hash('sha256', $token))->first();
        if (! $attempt) {
            return $this->deny(404);
        }
        if (CarbonImmutable::instance($attempt->token_expires_at)->isPast()) {
            return $this->deny(401);
        }
        // 別の通話（CallSid 不一致）からの呼び出しを拒否
        $sid = (string) $request->post('CallSid', '');
        if ($attempt->call_sid && $sid !== '' && ! hash_equals((string) $attempt->call_sid, $sid)) {
            return $this->deny(403);
        }

        return $attempt;
    }

    private function deny(int $status): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>', $status, ['Content-Type' => 'text/xml']);
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function twiml(Request $request, string $token): Response
    {
        $a = $this->resolve($request, $token, '');
        if ($a instanceof Response) {
            return $a;
        }
        if (in_array($a->status, ['scheduled', 'queued', 'initiated', 'ringing'], true)) {
            $a->update(['status' => 'in-progress']);
        }

        return $this->xml($this->phone->twiml($a, $token));
    }

    public function gather(Request $request, string $token): Response
    {
        $a = $this->resolve($request, $token, '/gather');
        if ($a instanceof Response) {
            return $a;
        }
        $reply = $this->phone->acknowledge($a, (string) $request->post('Digits', ''), CarbonImmutable::now('UTC'));
        $voice = e((string) config('monitoring.phone.voice', 'Polly.Mizuki'));

        return $this->xml('<?xml version="1.0" encoding="UTF-8"?><Response><Say language="ja-JP" voice="'.$voice.'">'.e($reply).'</Say><Hangup/></Response>');
    }

    public function status(Request $request, string $token): Response
    {
        $a = $this->resolve($request, $token, '/status');
        if ($a instanceof Response) {
            return $a;
        }
        $this->phone->status($a, $request->post(), CarbonImmutable::now('UTC'));

        return response('', 204);
    }
}
