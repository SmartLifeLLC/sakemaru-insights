<?php

namespace App\Monitoring\Notify;

use App\Monitoring\Services\SecretStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Twilio REST API（Programmable Voice）の最小クライアント。SDK は使わない（依存を増やさない）。
 * 認証情報は SecretStore（画面 > .env）。エラー文言に認証情報を含めない。
 */
class TwilioClient
{
    public function __construct(private readonly SecretStore $secrets) {}

    private function base(): string
    {
        return rtrim((string) config('monitoring.phone.api_base', 'https://api.twilio.com/2010-04-01'), '/');
    }

    /** @return array{ok:bool,status:?int,sid:?string,error:?string} */
    public function createCall(string $to, string $twimlUrl, string $statusUrl, int $timeout): array
    {
        $sid = $this->secrets->get('twilio.account_sid');
        $token = $this->secrets->get('twilio.auth_token');
        $from = $this->secrets->get('twilio.from_number');
        if (! $sid || ! $token || ! $from) {
            return ['ok' => false, 'status' => null, 'sid' => null, 'error' => 'not_configured'];
        }
        $body = http_build_query(['To' => $to, 'From' => $from, 'Url' => $twimlUrl, 'Method' => 'POST', 'StatusCallback' => $statusUrl, 'StatusCallbackMethod' => 'POST', 'Timeout' => $timeout])
            .'&StatusCallbackEvent=initiated&StatusCallbackEvent=ringing&StatusCallbackEvent=answered&StatusCallbackEvent=completed';
        try {
            $resp = Http::withBasicAuth($sid, $token)->timeout(10)->connectTimeout(5)
                ->withBody($body, 'application/x-www-form-urlencoded')->post($this->base().'/Accounts/'.$sid.'/Calls.json');
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => null, 'sid' => null, 'error' => 'connection'];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => null, 'sid' => null, 'error' => 'exception'];
        }
        $status = $resp->status();
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'sid' => (string) $resp->json('sid'), 'error' => null];
        }

        // Twilio のエラーコード（例: 21211 番号不正）だけを残す。メッセージ本文は残さない
        return ['ok' => false, 'status' => $status, 'sid' => null, 'error' => 'twilio_'.((int) $resp->json('code') ?: $status)];
    }

    /** 接続テスト（アカウント取得のみ。発信しない） @return array{ok:bool,message:string} */
    public function testConnection(): array
    {
        $sid = $this->secrets->get('twilio.account_sid');
        $token = $this->secrets->get('twilio.auth_token');
        if (! $sid || ! $token) {
            return ['ok' => false, 'message' => 'Twilio の Account SID / Auth Token が未設定です。'];
        }
        try {
            $resp = Http::withBasicAuth($sid, $token)->timeout(10)->get($this->base().'/Accounts/'.$sid.'.json');
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Twilio に接続できませんでした（ネットワーク）。'];
        }
        if ($resp->successful()) {
            return ['ok' => true, 'message' => 'Twilio に接続できました（アカウント状態: '.(string) $resp->json('status', '?').'）。'];
        }

        return ['ok' => false, 'message' => $resp->status() === 401 ? '認証に失敗しました（Account SID / Auth Token を確認）。' : 'Twilio がエラーを返しました（HTTP '.$resp->status().'）。'];
    }

    /** X-Twilio-Signature の検証（URL＋POST パラメータをキー順に連結した HMAC-SHA1、Base64） */
    public function validSignature(string $url, array $params, string $signature): bool
    {
        $token = $this->secrets->get('twilio.auth_token');
        if (! $token || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($url, $params, $token), $signature);
    }

    public static function sign(string $url, array $params, string $token): string
    {
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.(is_array($v) ? implode('', $v) : $v);
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }
}
