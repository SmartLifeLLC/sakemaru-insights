<?php

namespace App\Monitoring\Support;

use InvalidArgumentException;

/**
 * キーセットページング用の署名付きカーソル（仕様書 12.1）。
 * anchor_id・last_id・絞込み hash・page_size・発行時刻・方向を含み HMAC で署名。TTL 30 分。
 */
final class Cursor
{
    public function __construct(
        public readonly int $anchorId,
        public readonly int $lastId,
        public readonly string $filterHash,
        public readonly int $pageSize,
        public readonly int $issuedAt,
        public readonly string $direction = 'next', // next: id < last_id / prev: id > last_id
        public readonly string $scope = 'logs',
    ) {}

    public static function secret(): string
    {
        $env = config('monitoring.cursor_secret_env');
        $s = $env ? Env::get($env) : null;
        if (! $s) {
            $s = (string) config('app.key');
        }
        if (str_starts_with($s, 'base64:')) {
            $s = base64_decode(substr($s, 7));
        }

        return $s;
    }

    public function encode(): string
    {
        $payload = CanonicalJson::encode([
            'a' => $this->anchorId, 'l' => $this->lastId, 'f' => $this->filterHash, 'p' => $this->pageSize,
            'i' => $this->issuedAt, 'd' => $this->direction, 's' => $this->scope,
        ]);
        $b = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $sig = substr(hash_hmac('sha256', $b, self::secret()), 0, 32);

        return $b.'.'.$sig;
    }

    public static function decode(string $token, string $expectedFilterHash, string $expectedScope, int $ttlSeconds, int $now): self
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('cursor_malformed');
        }
        [$b, $sig] = $parts;
        if (! hash_equals(substr(hash_hmac('sha256', $b, self::secret()), 0, 32), $sig)) {
            throw new InvalidArgumentException('cursor_signature');
        }
        $json = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
        if (! is_array($json)) {
            throw new InvalidArgumentException('cursor_malformed');
        }
        $c = new self((int) $json['a'], (int) $json['l'], (string) $json['f'], (int) $json['p'], (int) $json['i'], (string) ($json['d'] ?? 'next'), (string) ($json['s'] ?? 'logs'));
        if ($c->scope !== $expectedScope) {
            throw new InvalidArgumentException('cursor_scope');
        }
        if (! hash_equals($expectedFilterHash, $c->filterHash)) {
            throw new InvalidArgumentException('cursor_filter_changed');
        }
        if ($now - $c->issuedAt > $ttlSeconds) {
            throw new InvalidArgumentException('cursor_expired');
        }

        return $c;
    }
}
