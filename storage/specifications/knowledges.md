# 酒丸算盤
本システム（酒丸算盤）は酒丸シリーズの統計管理システム
酒丸シリーズは以下のシステムで構成されていて、同じDBとセッションを共有している。


## 酒丸勘定衆 
酒類販売管理システム
基幹システム. 酒類販売管理の中核. このシステムをベースに他のシステムがサブシステムとして機能している。
Local サーバURL : https://sakemaru.test
DBのprefix : なし

## 酒丸蔵
酒類販売の倉庫管理システム. いわばWMS
発注・入荷・出荷・在庫管理・倉庫Handyなどを管理
Local サーバURL : https://wms.sakemaru.test
DBのbase prefix : wms_

## 酒丸乃蓮
小売販売管理システム. 
顧客が小売店舗を運用している場合に店舗運用関連のシステム
店舗のPoSと連動しシステムを連携
現在は東芝TECのPoSとデータの連携中
Local サーバURL : https://trade.sakemaru.test
DBのbase prefix : ret_

## 酒丸飛脚
容器回収・集金システム
配送員の配送管理システム。容器の回収や現金回収がメイン
Handy端末と携帯プリントによる領収書印刷も可能

Local サーバURL : https://delivery.sakemaru.test
DBのbase prefix : dlv_


## 酒丸算盤
本システム・上記各システムで生成されたデータを分析するために、集計と表示を行うサイト
Local サーバURL : https://insights.sakemaru.test
DBのbase prefix : ins_



