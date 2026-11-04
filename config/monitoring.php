<?php

/*
|--------------------------------------------------------------------------
| 運用監視（Sakemaru Insights Monitoring）設定
|--------------------------------------------------------------------------
|
| 1 DB = 1 client。client・環境・deployment_id は配置設定で固定し、
| リクエストやホスト名から推測しない（仕様書 1.2 / 4.2）。
| 秘密値（Webhook URL・収集器の署名鍵）は env 参照名だけを持ち、
| DB・画面・ログへは保存しない（仕様書 13.1 / 17）。
|
*/

$collectors = [
    // 直書きの例（通常は下の MONITOR_LOG_COLLECTORS で各サーバーの .env から宣言する）:
    // [
    //     'collector_uuid' => '7c7a2f6e-1111-4e8f-9b7e-000000000001',
    //     'application_code' => 'TRADE',
    //     'node_code' => 'web1',
    //     'secret_env' => 'MONITOR_COLLECTOR_SECRET_TRADE_WEB1',
    //     'log_files' => ['/var/www/sakemaru-trade/storage/logs/laravel.log'],
    //     'parser' => 'laravel',
    // ],
];

// .env からの収集器宣言（このサーバーにあるアプリログ）。形式: APP=/abs/path[,/abs/path2];APP2=/abs/path
// 例: MONITOR_LOG_COLLECTORS="WMS=/var/www/hana/sakemaru-wms/storage/logs/laravel*.log;TRADE=/var/www/hana/sakemaru-trade/storage/logs/laravel*.log"
// パスはワイルドカード可（日次ログ laravel-YYYY-MM-DD.log）。collector_uuid は配置 ID・ノード・アプリから決定的に生成。
// 署名鍵 MONITOR_COLLECTOR_SECRET_<APP>_<NODE> は別サーバーへ送る（MONITOR_INGEST_URL）場合だけ必要。
$__node = (string) env('MONITOR_NODE_CODE', 'local');
$__dep = (string) env('MONITOR_DEPLOYMENT_ID', 'local-insights');
foreach (array_filter(array_map('trim', explode(';', (string) env('MONITOR_LOG_COLLECTORS', '')))) as $__decl) {
    [$__app, $__paths] = array_pad(explode('=', $__decl, 2), 2, '');
    $__app = strtoupper(trim($__app));
    $__paths = array_values(array_filter(array_map('trim', explode(',', $__paths))));
    if ($__app === '' || $__paths === []) {
        continue;
    }
    $collectors[] = [
        'collector_uuid' => \App\Monitoring\Support\Uuid::v5(\App\Monitoring\Support\Uuid::v5(\App\Monitoring\Support\Uuid::NS_MONITOR, 'sakemaru-monitor-log'), $__dep.'|'.$__node.'|'.$__app),
        'application_code' => $__app,
        'node_code' => $__node,
        'secret_env' => 'MONITOR_COLLECTOR_SECRET_'.$__app.'_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $__node)),
        'log_files' => $__paths,
        'parser' => 'laravel',
    ];
}

// config:cache 後は env() が使えないため、秘密値の env 参照をここで解決して config に写す（App\Monitoring\Support\Env::get が参照）。
$secretEnvNames = array_merge(
    ['MONITOR_SLACK_ERROR_WEBHOOK', 'MONITOR_METRICS_SECRET', 'MONITOR_CURSOR_SECRET', 'MONITOR_CHECK_OWNER_NODE',
        'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM_NUMBER'],
    array_values(array_filter(array_map(fn ($c) => $c['secret_env'] ?? null, $collectors))),
);
$secretValues = [];
foreach ($secretEnvNames as $name) {
    $secretValues[$name] = env($name);
}

return [

    'enabled' => (bool) env('MONITOR_ENABLED', true),

    // 秘密値の写し（config 構築時に解決）。画面・ログに出さない
    'secret_values' => $secretValues,

    /*
    | 監視対象DBと環境を識別する運用上の名前。同じDBを監視する全ノードで共有する。
    | tenant ではない。別DB・別環境には別の値を割り当てる。
    */
    'deployment' => [
        'id' => env('MONITOR_DEPLOYMENT_ID', 'local-insights'),
        'client_name' => env('MONITOR_CLIENT_NAME', '華'),
        'environment' => env('MONITOR_ENVIRONMENT', env('APP_ENV', 'local')), // production / staging / local
        'timezone' => 'Asia/Tokyo',
        // 業務DB照合（CheckRunner）の owner ノード。これ以外のノードでは monitor:run-checks を拒否する。
        'check_owner_node' => env('MONITOR_CHECK_OWNER_NODE', env('MONITOR_NODE_CODE', 'local')),
        'check_owner_explicit' => env('MONITOR_CHECK_OWNER_NODE') !== null, // 複数台では明示が必須（preflight で確認）
        'node_code' => env('MONITOR_NODE_CODE', 'local'),
    ],

    /*
    | 同じDBを利用するサイト（アプリ）。application_code は固定語彙。
    | coverage は配置時の確認結果で更新する。未確認は NOT_CONFIGURED のまま「未監視」と表示する。
    */
    'sites' => [
        // 表示名は日本語で統一（v2 仕様書 第2章）。コードは英字のまま。
        'CORE' => ['display_name' => '勘定衆', 'repo' => 'sakemaru-ai-core', 'role' => '販売管理・統計', 'owner_group' => 'core-ops', 'sort' => 10],
        'TRADE' => ['display_name' => '乃蓮', 'repo' => 'sakemaru-trade', 'role' => '小売取引・POS 連携', 'owner_group' => 'trade-ops', 'sort' => 20],
        'WMS' => ['display_name' => '蔵', 'repo' => 'sakemaru-wms', 'role' => '倉庫・自動発注・EOS/JX', 'owner_group' => 'wms-ops', 'sort' => 30],
        'DELIVERY' => ['display_name' => '飛脚', 'repo' => 'sakemaru-delivery', 'role' => '配送・集金・容器回収', 'owner_group' => 'delivery-ops', 'sort' => 40],
        'SEARCH' => ['display_name' => '千里眼', 'repo' => 'sakemaru-search', 'role' => '商品検索', 'owner_group' => 'search-ops', 'sort' => 50],
        'INSIGHTS' => ['display_name' => '算盤', 'repo' => 'sakemaru-insights', 'role' => '監視・分析（自己監視）', 'owner_group' => 'insights-ops', 'sort' => 90],
    ],

    /*
    | ファイルログ収集器。ノード・アプリごとに許可済みの絶対パスを固定する（仕様書 6.3）。
    | secret_env は HMAC 署名鍵の env 参照名。鍵本体はここに書かない。
    */
    'collectors' => $collectors,

    'ingest' => [
        'max_batch_events' => 100,
        'max_batch_bytes' => 256 * 1024,
        'max_transaction_events' => 20,
        'timestamp_tolerance_seconds' => 300,
        'summary_max_chars' => 500,
        'evidence_max_bytes' => 16 * 1024,
        'sample_max_bytes' => 8 * 1024,
        'schema_version' => 1,
    ],

    'collector' => [
        'read_bytes_per_second' => 256 * 1024,
        'initial_lookback_hours' => 24,
        'initial_max_bytes' => 50 * 1024 * 1024,
        'aggregate_window_seconds' => 60,
        'spool_dir' => env('MONITOR_SPOOL_DIR', storage_path('monitor/spool')),
        'state_dir' => env('MONITOR_STATE_DIR', storage_path('monitor/state')),
        'spool_max_bytes' => 200 * 1024 * 1024,
        'spool_error_reserve_bytes' => 50 * 1024 * 1024,
        'spool_warning_ratio' => 0.70,
        'spool_error_ratio' => 0.85,
        'spool_throttle_ratio' => 0.95,
        'ingest_url' => env('MONITOR_INGEST_URL'), // 未設定なら同一ホスト直接取込
        'heartbeat_interval_seconds' => 60,
        'min_level' => 'ERROR', // アプリログで収集する最低レベル（INFO 等は許可パターンのみ）
        'info_patterns' => [
            // 運用チェック・バッチ結果など許可した INFO 行の正規表現（本文に対して）
            '/^\[batch\]/u',
            '/schedule:run|Command finished|バッチ完了/u',
        ],
    ],

    /*
    | 業務DB（monitor_source）への負荷予算（仕様書 7.1）。
    */
    'budget' => [
        'source_max_concurrency' => 1,
        'light_sql_timeout_ms' => 1000,
        'detail_sql_timeout_ms' => 3000,
        'check_timeout_seconds' => 45,
        'run_timeout_seconds' => 90,
        'sql_budget_ms_per_5min' => 5000,
        'daily_sql_budget_ms' => 20000,
        'lock_wait_timeout_seconds' => 1,
        'min_recheck_interval_seconds' => 300,
        'heavy_min_interval_seconds' => 1800,
        'backoff_minutes' => [15, 30, 60],
        'consecutive_failures_before_backoff' => 3,
    ],

    'retention' => [
        'info_days' => 30,
        'warning_days' => 90,
        'error_days' => 365,
        'resolved_incident_days' => 365,
        'notification_days' => 90,
        'audit_days' => 365,
        'counter_days' => 365,
        'delete_batch_rows' => 500,
        'delete_max_rows_per_run' => 5000,
        'delete_max_seconds_per_run' => 5,
        'delete_pause_ms' => 200,
        'backlog_grace_hours' => 24,
    ],

    'notification' => [
        // route_key → 表示名と Webhook URL の env 参照名。URL は env から読み、保存しない。
        'routes' => [
            'error_channel' => [
                'display_name' => env('MONITOR_SLACK_ERROR_CHANNEL_NAME', '#sakemaru-errors'),
                'webhook_env' => 'MONITOR_SLACK_ERROR_WEBHOOK',
                'mention' => env('MONITOR_SLACK_ERROR_MENTION'), // 承認済みグループのみ（例: <!subteam^S0123>）
            ],
        ],
        'default_route' => 'error_channel',
        'first_notify_slo_seconds' => 60,
        'reminder' => [
            'ERROR' => ['unacknowledged_first' => 30 * 60, 'unacknowledged_repeat' => 120 * 60, 'in_progress_repeat' => 240 * 60],
            'CRITICAL' => ['unacknowledged_first' => 10 * 60, 'unacknowledged_repeat' => 30 * 60, 'in_progress_repeat' => 60 * 60],
        ],
        'retry_delays_seconds' => [30, 120, 600, 1800, 3600],
        'retry_jitter_seconds' => 15,
        'rate_limit_per_minute' => 10,
        'max_per_hour' => 60,            // Slack の 1 時間の上限。超えた分はまとめ送信で翌時間へ（多発防止）
        'batch_max_incidents' => 20,
        'stale_pending_seconds' => 300,
        'lease_seconds' => 120,
        'http_timeout_seconds' => 10,
        'max_suppression_hours' => 24,
        'max_pause_minutes' => 240,      // 「アラート一時停止」ボタンの最長（期限で自動再開）
        'global_disable' => (bool) env('MONITOR_NOTIFY_DISABLED', false),
        'global_disable_reason' => env('MONITOR_NOTIFY_DISABLED_REASON'),
        'global_disable_until' => env('MONITOR_NOTIFY_DISABLED_UNTIL'),
        'fallback_contact' => env('MONITOR_FALLBACK_CONTACT'), // 代替通知先（運用責任者が指定）
        'external_heartbeat_url' => env('MONITOR_EXTERNAL_HEARTBEAT_URL'), // ホスト外死活監視
    ],

    /*
    | HTTP 外形監視（v2 第3章）。URL は ins_monitor_targets（kind=HTTP_PROBE）に登録したものだけを叩く。
    | 送信先ホストは allowed_hosts（顧客の自サービス）に限る。リダイレクトは追わない。
    */
    'probe' => [
        'interval_seconds' => 60,
        'timeout_seconds' => 5,
        'connect_timeout_seconds' => 3,
        'warning_after_failures' => 1,
        'error_after_failures' => 3,
        'slow_warning_ms' => 2000,
        'results_days' => 30,
        'stale_minutes' => 5,
        'user_agent' => 'SakemaruInsightsProbe/1.0',
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITOR_PROBE_ALLOWED_HOSTS', ''))))),
        'allow_http' => (bool) env('MONITOR_PROBE_ALLOW_HTTP', false), // 既定は https のみ
    ],

    /*
    | サーバー資源（v2 第5章）。各サーバーの monitor:sample-resources（常駐）が /proc を直読みする。
    | ノードの識別は各サーバーの .env（MONITOR_NODE_CODE / ROLE / DISPLAY_NAME）。
    */
    'resources' => [
        'node' => [
            'code' => env('MONITOR_NODE_CODE', 'local'),
            'role' => env('MONITOR_NODE_ROLE', 'web'),
            'display_name' => env('MONITOR_NODE_DISPLAY_NAME'),
        ],
        'proc_root' => env('MONITOR_PROC_ROOT', '/proc'),
        /*
        | プロセスの生存確認（1 分ごとに /proc を走査。外部コマンド・HTTP は使わない）。
        | ELB の後ろでは外部からの /up では 1 台の停止を検知できないため、各サーバーで自分のプロセスを確認する。
        | このサーバーで必須のプロセスは .env の MONITOR_NODE_PROCESSES（カンマ区切りのキー）。未設定なら役割（role）の既定値。
        */
        'processes' => [
            'apache' => ['label' => 'Apache', 'comm' => ['httpd', 'apache2']],
            'php-fpm' => ['label' => 'PHP-FPM', 'comm' => ['php-fpm'], 'prefix' => true],   // php-fpm8.3 なども一致
            'supervisor' => ['label' => 'supervisord', 'comm' => ['supervisord'], 'cmdline' => 'supervisord'], // python 実行でも cmdline で判定
            'cron' => ['label' => 'cron', 'comm' => ['cron', 'crond']], // Laravel スケジューラ（schedule:run）の起点。MONITOR_NODE_PROCESSES に cron を含めて必須にする
        ],
        'process_scan_seconds' => 60,
        'required_processes' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITOR_NODE_PROCESSES', ''))))),
        'role_processes' => [
            'web' => ['apache', 'php-fpm', 'supervisor'],
            'batch' => ['php-fpm', 'supervisor'],
            'db' => [],
            'other' => [],
        ],
        'data_dir' => env('MONITOR_METRICS_DIR', storage_path('monitor/metrics')),
        'sample_seconds' => 30,
        'burst' => [
            'enabled' => true,
            'poll_seconds' => 1,        // 1 秒ごとに軽量読み取り（リングバッファ）。ファイルへ書くのは警告域の前後だけ
            'pre_seconds' => 120,
            'post_seconds' => 120,
            'cpu_pct' => 70,
            'iowait_pct' => 10,
            'mem_available_pct' => 15,
            'load_per_core' => 1.5,
        ],
        'local_days' => 60,
        'db_days' => 365,
        'ship_seconds' => 60,           // latest（直近 10 分の 30 秒値を含む）の送信間隔
        'socket_stats_seconds' => 60,   // ss -s の実行間隔
        'exclude_fstypes' => ['tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'devpts', 'mqueue', 'debugfs', 'tracefs', 'securityfs', 'pstore', 'bpf', 'autofs', 'hugetlbfs', 'configfs', 'fusectl', 'binfmt_misc', 'nsfs', 'ramfs', 'efivarfs', 'rpc_pipefs',
            // ネットワーク FS は statvfs が固まることがあるため既定で除外（必要なら外す）
            'nfs', 'nfs4', 'cifs', 'smb3', '9p', 'fuse.sshfs', 'fuse.rclone', 'fuse.s3fs', 'fuse.gcsfuse'],
        'exclude_mount_prefixes' => ['/snap/', '/run/', '/sys/', '/proc/', '/dev/'],
        'exclude_devices' => '/^(loop|ram|sr|dm-|zram)/',
        'exclude_interfaces' => '/^(lo|docker|veth|br-|virbr)/',
        'secret_env' => 'MONITOR_METRICS_SECRET', // 同じ顧客の全ノードで共有する HMAC 鍵（env 参照名）
        'metrics_stale_warning_seconds' => 180,
        'metrics_stale_error_seconds' => 600,
        // しきい値の既定値。ノード別に thresholds_json で上書き（監視ルール画面）。duration は秒。
        'thresholds' => [
            'cpu_pct' => ['warning' => 80, 'error' => 90, 'duration' => 300],
            'iowait_pct' => ['warning' => 20, 'error' => 30, 'duration' => 300],
            'load_per_core' => ['warning' => 1.5, 'error' => 2.0, 'duration' => 600],
            'mem_available_pct' => ['warning' => 10, 'error' => 5, 'duration' => 60, 'direction' => 'below'],
            'swap_used_pct' => ['warning' => 50, 'error' => 80, 'duration' => 300],
            'disk_used_pct' => ['warning' => 80, 'error' => 85, 'critical' => 95, 'duration' => 0],
            'inode_used_pct' => ['warning' => 80, 'error' => 90, 'duration' => 0],
            // 必須プロセスが 0 件の状態が続いた秒数（再起動の一時的な停止は除く）
            'process_missing' => ['error' => 300, 'duration' => 0],
        ],
    ],

    /*
    | メール通知（v2 第7章）。SMTP の接続情報は通知設定画面（ins_monitor_secrets、暗号化）> .env（MAIL_*）の順に使う。
    | 宛先・対象は ins_monitor_settings（画面）で上書きできる。ここは既定値。
    */
    'mail' => [
        'enabled' => (bool) env('MONITOR_MAIL_ENABLED', true),
        'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITOR_MAIL_TO', 'admin@sakemaru.ai'))))),
        'kinds' => ['NEW', 'RECURRENCE', 'ESCALATION'],   // 継続（REMINDER）・復旧（RECOVERY）は既定 OFF
        'include_reminder' => false,
        'include_recovery' => false,
        'slack_fallback_minutes' => 5,                    // Slack が BLOCKED / 5 分以上未送信なら代替でメール
        'min_interval_minutes' => 5,                      // メールは 5 分に 1 通にまとめる（多発防止）
        'max_per_hour' => 20,                             // 1 時間の上限。超えた分は翌時間にまとめて送る
        'batch_max_incidents' => 20,
        'timeout_seconds' => 15,
    ],

    /*
    | 電話通知（Twilio Programmable Voice、v2 第7.2）。夜間（既定 22:00–08:00 JST）の ERROR / CRITICAL だけ。
    | 認証情報は通知設定画面 > .env（TWILIO_*）。誤発信防止に allow_env と配置の環境が一致する場合だけ発信する。
    */
    'phone' => [
        'enabled' => (bool) env('MONITOR_PHONE_ENABLED', true),
        'window_from' => '22:00',
        'window_to' => '08:00',
        'severities' => ['ERROR', 'CRITICAL'],
        'kinds' => ['NEW', 'RECURRENCE', 'ESCALATION'],
        'allow_env' => env('MONITOR_PHONE_ALLOW_ENV', 'production'),
        'next_contact_seconds' => 120,   // 応答なし・不在・1 以外 → 2 分後に次の担当者
        'round_interval_seconds' => 900, // 名簿 1 巡で確認なし → 15 分後に再度
        'max_rounds' => 3,
        'ring_timeout_seconds' => 30,
        'token_ttl_seconds' => 1800,
        // 多発防止（通知設定画面で変更可）
        'delay_minutes' => 5,            // 発信前の猶予。その間に復旧・確認されたら電話しない
        'cooldown_minutes' => 30,        // 発信列が終わってから次の列を始めるまでの間隔（その間の案件は 1 本にまとめる）
        'per_incident_cooldown_hours' => 1,  // 同じ案件は 1 時間に 1 回まで（再発しても Slack・メールのみ）
        'max_columns_per_window' => 3,   // 1 晩（時間帯）あたりの発信列の上限。超えたら電話は止めて Slack・メールのみ
        'max_calls_per_contact_per_window' => 4, // 担当者 1 人あたり 1 晩の着信上限
        'say_max_chars' => 80,
        'voice' => 'Polly.Mizuki',
        'api_base' => 'https://api.twilio.com/2010-04-01',
        'public_base_url' => env('MONITOR_PUBLIC_BASE_URL'), // Twilio から到達できる Insights の URL（未設定なら APP_URL）
    ],

    'ui' => [
        'page_size_default' => 20,
        'page_size_options' => [20, 50, 100],
        'page_size_max' => 100,
        'cursor_ttl_seconds' => 1800,
        'default_log_window_hours' => 24,
        'max_log_window_days' => 30,
        'max_warning_window_days' => 90,
        'max_error_window_days' => 365,
        'auto_refresh_seconds' => 60,
        'detail_history_page_size' => 50,
    ],

    /*
    | 権限。既存の権限体系が未整備のため、初版は設定で対応付ける（仕様書 17）。
    | 値はユーザーの email または code の配列。'*' で全認証ユーザー。
    | サイト制限は 'sites' => ['TRADE', ...]（省略時は全サイト）。
    */
    'permissions' => [
        'monitor.view' => ['*'],
        'monitor.respond' => array_filter(explode(',', (string) env('MONITOR_RESPONDERS', '*'))),
        'monitor.manage' => array_filter(explode(',', (string) env('MONITOR_MANAGERS', ''))),
        'monitor.delivery_admin' => array_filter(explode(',', (string) env('MONITOR_DELIVERY_ADMINS', ''))),
    ],

    'cursor_secret_env' => 'MONITOR_CURSOR_SECRET', // 未設定時は APP_KEY を利用
];
