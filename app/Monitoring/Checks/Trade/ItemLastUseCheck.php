<?php

namespace App\Monitoring\Checks\Trade;

use App\Monitoring\Contracts\CheckDefinition;
use App\Monitoring\Services\CheckContext;
use App\Monitoring\Services\CheckResult;
use App\Monitoring\Services\Observation;

/**
 * trade.item_last_use: 商品検索コード最終使用日の更新（23:00 バッチ＋30分）。
 * 対象テーブル・列が未確認のため初版は未実装（implemented=false）。画面では「未実装」と表示し、HEALTHY にしない。
 */
class ItemLastUseCheck extends TradeCheck
{
    public function definition(): CheckDefinition
    {
        return new CheckDefinition('trade.item_last_use', 'TRADE', '商品検索コード最終使用日', '販売実績と最終使用日の比較（23:00 バッチ）', 1, 1800, 1800, 'RB-06', true, true, 1, false, [], [],
            '最終使用日の保存先テーブル・列と入力締切の根拠を実スキーマで確認後に実装する。');
    }

    public function evaluate(CheckContext $ctx): CheckResult
    {
        return CheckResult::single(Observation::unknown('未実装のため判定しません。', ['check' => 'unimplemented'], null, [], 'NOT_IMPLEMENTED'));
    }
}
