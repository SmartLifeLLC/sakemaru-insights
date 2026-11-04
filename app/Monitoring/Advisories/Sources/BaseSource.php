<?php

namespace App\Monitoring\Advisories\Sources;

use App\Monitoring\Advisories\AdvisorySource;
use App\Monitoring\Support\Env;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class BaseSource implements AdvisorySource
{
    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('monitoring.advisories.http_timeout_seconds', 20))
            ->connectTimeout(10)
            ->withUserAgent((string) config('monitoring.advisories.user_agent'))
            ->retry(2, 1500, throw: false);
    }

    protected function endpoint(): string
    {
        return (string) config('monitoring.advisories.endpoints.'.$this->key());
    }

    /** @return array<string,mixed> */
    protected function products(): array
    {
        return (array) config('monitoring.advisories.products');
    }

    protected function secret(string $configKey): ?string
    {
        return Env::get((string) config('monitoring.advisories.'.$configKey));
    }

    /**
     * GET して成功応答を返す。失敗（HTTP 4xx/5xx・接続不可）は RuntimeException にそろえる（本文は先頭だけ、秘密値は含めない）。
     *
     * @param  array<string,mixed>  $query
     */
    protected function get(PendingRequest $req, string $url, array $query, string $what): Response
    {
        try {
            $r = $req->get($url, $query);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw new \RuntimeException($what.' HTTP '.$e->response->status().' '.mb_substr(preg_replace('/\s+/', ' ', (string) $e->response->body()), 0, 160), 0, $e);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException($what.' 接続失敗: '.mb_substr($e->getMessage(), 0, 160), 0, $e);
        }
        $this->assertOk($r, $what);

        return $r;
    }

    /** 応答の確認。失敗は RuntimeException（本文は先頭だけ、秘密値は含めない） */
    protected function assertOk(Response $r, string $what): void
    {
        if (! $r->successful()) {
            throw new \RuntimeException($what.' HTTP '.$r->status().' '.mb_substr(preg_replace('/\s+/', ' ', (string) $r->body()), 0, 160));
        }
    }

    /** 文章中に製品名が出るか（KEV / AWS / NVD 補助用） */
    protected function matchProductsInText(string $text): array
    {
        $text = strtolower($text);
        $keys = [];
        foreach ($this->products() as $key => $p) {
            foreach ((array) ($p['kev'] ?? []) as $needle) {
                if ($needle !== '' && str_contains($text, strtolower($needle))) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
