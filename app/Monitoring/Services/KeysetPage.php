<?php

namespace App\Monitoring\Services;

use App\Monitoring\Support\Cursor;

/** キーセットページの結果。総件数・総ページ数は持たない（仕様書 12.1）。 */
final class KeysetPage
{
    /** @param array<int,mixed> $items */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly ?string $prevCursor,
        public readonly int $anchorId,
        public readonly int $pageSize,
        public readonly string $asOf,
        public readonly bool $hasNewer = false,
    ) {}

    /**
     * @param  callable(int $anchorId, ?int $afterId, ?int $beforeId, int $limit): array<int,object>  $fetch  ORDER BY id DESC, LIMIT limit を実行する
     * @param  callable(): int  $maxId  現在の最大 id（anchor 用）
     */
    public static function build(callable $fetch, callable $maxId, ?string $cursorToken, string $filterHash, string $scope, int $pageSize, int $ttl, int $now): self
    {
        $cursor = $cursorToken ? Cursor::decode($cursorToken, $filterHash, $scope, $ttl, $now) : null;
        $pageSize = max(1, min($pageSize, (int) config('monitoring.ui.page_size_max', 100)));
        $anchor = $cursor?->anchorId ?? (int) $maxId();
        $asOf = gmdate('Y-m-d\TH:i:s\Z', $now);

        if ($cursor && $cursor->direction === 'prev') {
            // 逆方向 seek: id > last_id を昇順で取り、反転
            $rows = $fetch($anchor, $cursor->lastId, null, $pageSize + 1);
            $hasMorePrev = count($rows) > $pageSize;
            $rows = array_slice($rows, 0, $pageSize);
            $rows = array_reverse($rows);
            $items = $rows;
            $firstId = $items ? (int) $items[0]->id : $cursor->lastId;
            $lastId = $items ? (int) end($items)->id : $cursor->lastId;
            $next = (new Cursor($anchor, $lastId, $filterHash, $pageSize, $now, 'next', $scope))->encode();
            $prev = $hasMorePrev ? (new Cursor($anchor, $firstId, $filterHash, $pageSize, $now, 'prev', $scope))->encode() : null;
        } else {
            $rows = $fetch($anchor, null, $cursor?->lastId, $pageSize + 1);
            $hasNext = count($rows) > $pageSize;
            $items = array_slice($rows, 0, $pageSize);
            $lastId = $items ? (int) end($items)->id : ($cursor?->lastId ?? $anchor);
            $firstId = $items ? (int) $items[0]->id : null;
            $next = $hasNext ? (new Cursor($anchor, $lastId, $filterHash, $pageSize, $now, 'next', $scope))->encode() : null;
            $prev = ($cursor && $firstId !== null) ? (new Cursor($anchor, $firstId, $filterHash, $pageSize, $now, 'prev', $scope))->encode() : null;
        }
        $hasNewer = ((int) $maxId()) > $anchor;

        return new self($items, $next, $prev, $anchor, $pageSize, $asOf, $hasNewer);
    }
}
