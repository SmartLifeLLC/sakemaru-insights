# 算盤 監視 v2.3 — 脆弱性情報の収集と表示

> 状態: 実装済み（2026-11-05）。元資料: 「酒丸 セキュリティ脆弱性情報収集手段 調査メモ」（2026-11-04）。
> 目的: Apache / PHP / Laravel / Livewire / Ubuntu / OpenSSL / MySQL / AWS に関する公開脆弱性情報を定期的に集め、算盤 監視の画面とダッシュボードで確認できるようにする。
> 範囲: 収集・保存・表示・状態管理・監視項目。**影響判定は補助情報**（導入版が範囲に入るかの機械的な照合）で、最終判断は担当者が行う。OS・ライブラリの更新は画面から行わない。

## 1. 情報源と取得

| キー | 情報源 | 取得 | 絞り込み | 照合 |
| --- | --- | --- | --- | --- |
| `nvd` | NVD CVE API 2.0 | 製品ごとに `virtualMatchString`（CPE 前方一致）＋最終更新日の範囲。**期間内に公開された CVE だけ保存**（古い CVE の軽微な更新は除外） | Apache HTTP Server / PHP / Laravel / Livewire / OpenSSL / MySQL（Ubuntu は USN に任せる） | PHP は CPE の版範囲と `PHP_VERSION` を比較 |
| `github` | GitHub Advisory Database | `ecosystem=composer` で期間内に更新された全件 | **composer.lock に入っているパッケージだけ保存** | 影響範囲（`>= 9.0, < 9.52.17` 形式）と導入版を比較 |
| `ubuntu` | Ubuntu Security Notices（`notices.json`） | 導入リリース（`/etc/os-release`）の通知を新しい順 | 修正パッケージが対象製品 or 導入済み deb | 導入版 < 修正版（`dpkg --compare-versions`） |
| `kev` | CISA KEV | 全件 JSON | 期間内に追加され、ベンダー/製品名が対象製品に一致 | 他情報源の同じ CVE にも `kev=true` を付ける |
| `aws` | AWS Security Bulletins | RSS | 期間内の全件 | — |
| `jvn` | JVN iPedia（MyJVN `getVulnOverviewList`） | 製品名キーワードごとに公開日の範囲 | Apache HTTP Server / PHP / Laravel / Livewire / OpenSSL / MySQL | CVSS 基本値 → 重大度 |
| （付加） | FIRST EPSS | 新規 CVE をまとめて問い合わせ | — | `epss`（30 日以内の悪用確率）を保存 |

- 取得は **owner ノード**の `sakemaru-monitor-advisories@<dep>.timer`（6 時間ごと、`monitor:fetch-advisories`）。初回は 30 日遡り（`MONITOR_ADVISORY_LOOKBACK_DAYS`）、以後は前回成功の開始時刻から 1 日重ねて取る。
- 失敗した情報源は `ins_monitor_advisory_fetches` に記録して次へ進む（他を止めない）。
- 外部へ送るのは製品名・CPE・リリース名・日付だけ。**導入パッケージ一覧や版は送らない**（照合はローカル）。
- 任意の API キー: `MONITOR_NVD_API_KEY`（NVD の制限緩和）、`MONITOR_GITHUB_TOKEN`（匿名 60 回/時 → 認証枠）。env だけで画面・ログ・DB に出さない。
- 情報源の選択: `MONITOR_ADVISORY_SOURCES=nvd,github,ubuntu,kev,aws,jvn`。収集全体の停止: `MONITOR_ADVISORIES_ENABLED=false`。
- 外向き HTTPS（`services.nvd.nist.gov`, `api.github.com`, `ubuntu.com`, `www.cisa.gov`, `aws.amazon.com`, `jvndb.jvn.jp`, `api.first.org`）が必要。プロキシ環境では `HTTPS_PROXY` を unit の Environment に設定する。

## 2. 保存（`ins_monitor_advisories`）

1 行 = 情報源 × ID（`source`, `external_id` で一意）。主な列: `cve_id`, `title`, `summary`（原文）, `severity`（CRITICAL/HIGH/MEDIUM/LOW/UNKNOWN）, `cvss`, `epss`, `kev`, `components_json`（製品キー）, `affected_json`（影響パッケージ・版・導入版）, `installed_affected`, `published_at`, `first_seen_at`, `status`（NEW / ACKNOWLEDGED / NOT_APPLICABLE / RESOLVED）, 状態変更者・理由・時刻。
同じ ID を再取得したときは内容だけ更新し、**状態は変えない**。製品は和集合、導入版の影響はどちらかが真なら真。

## 3. 画面

- 監視 → **脆弱性情報**（`/monitor/advisories`）: タブ「新規 / 要対応 / 確認済み / 対象外 / 対応済み / すべて」、製品・重大度・情報源・キーワードで絞り込み。上部に 1 行の要約と情報源ごとの取得状態（緑=成功・赤=失敗、ホバーで最終取得時刻）。
- 行クリックで詳細（概要の原文、影響範囲、EPSS、情報源リンク）。「確認済みにする」は 1 クリック、「対象外」「対応済み」は理由必須（監査に記録）。
- **要対応** = 新規のうち「重大（CVSS 9.0 以上）」「悪用確認（KEV）」「導入版が影響範囲」のいずれか。
- ダッシュボード: KPI の上に 1 行。「現在、新規の脆弱性情報はありません。最終取得 … (6 / 6 情報源)」/「新しい脆弱性情報が n 件あります（重大・悪用確認なし）」/「脆弱性報告が n 件あります（重大 x・悪用確認 y・導入版が影響 z）。確認してください。」。クリックで該当タブへ。

## 4. 監視項目 `monitor.advisories`（INSIGHTS / 監視基盤の自己監視）

| 判定 | 結果 |
| --- | --- |
| 要対応が未確認のまま | WARNING `ADVISORY_UNREVIEWED`（Slack 通知。確認済み/対象外/対応済みにすると復旧） |
| 要対応でない新規だけ | 正常（画面とダッシュボードの 1 行で分かる） |
| 一度も取得していない | UNKNOWN `ADVISORY_FETCH_NEVER` |
| 最終取得成功が `stale_hours`（既定 30h）より古い | WARNING `ADVISORY_FETCH_STALE` |

対処手順: RB-23「脆弱性報告の確認と対応」。

## 5. 限界

- 情報源と製品の対応表（`config/monitoring.php` の `advisories.products`）に無い製品は集めない。網羅性は保証しない。
- 導入版の照合は NVD（PHP）、GitHub（Composer）、Ubuntu（deb）だけ。Apache の版は Ubuntu の deb 版で見る（NVD の Apache 行は照合なし）。
- JVN は公開日ベースの検索のため、更新だけの再公開は拾わないことがある。
- 「要対応」の判断は機械的。誤検知（該当モジュール未使用など）は「対象外」＋理由で片付ける。
