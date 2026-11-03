<?php

namespace App\Monitoring\Support;

/** 線アイコン（モックアップと同じパス。外部アイコンフォントを使わない） */
final class Icon
{
    public const PATHS = [
        'grid' => 'M3 3h6v6H3z M15 3h6v6h-6z M3 15h6v6H3z M15 15h6v6h-6z', 'pulse' => 'M2 12h5l3-8 4 16 3-8h5', 'chart' => 'M4 3v17h17 M8 14l4-5 4 3 5-7',
        'server' => 'M4 3h16v7H4z M4 14h16v7H4z M7 6.5h.1 M7 17.5h.1 M11 6.5h6 M11 17.5h6', 'alert' => 'M12 3 2 21h20L12 3z M12 9v5 M12 17h.01',
        'logs' => 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5', 'sliders' => 'M5 3v5 M5 12v9 M12 3v10 M12 17v4 M19 3v2 M19 9v12 M2 8h6v4H2z M9 13h6v4H9z M16 5h6v4h-6z',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M9 21h6', 'book' => 'M3 4h7l2 2 2-2h7v16h-7l-2 2-2-2H3z M12 6v16',
        'search' => 'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6', 'sun' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M12 2v2 M12 20v2 M2 12h2 M20 12h2 M5 5l1.5 1.5 M17.5 17.5 19 19 M5 19l1.5-1.5 M17.5 6.5 19 5',
        'moon' => 'M20 15A8 8 0 0 1 9 4a9 9 0 1 0 11 11z', 'refresh' => 'M20 7v5h-5 M4 17v-5h5 M20 12a8 8 0 0 0-14-6 M4 12a8 8 0 0 0 14 6', 'arrow' => 'M5 12h14 M14 7l5 5-5 5',
        'down' => 'M12 3v12 M7 10l5 5 5-5 M4 17v4h16v-4', 'plus' => 'M12 5v14 M5 12h14', 'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2', 'check' => 'M5 12l4 4L19 6',
        'info' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 11v6 M12 7h.01', 'close' => 'M5 5l14 14 M19 5 5 19', 'mail' => 'M3 5h18v14H3z M3 5l9 8 9-8',
        'message' => 'M3 4h18v13H8l-5 4V4z M7 8h10 M7 12h7', 'shield' => 'M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6L12 2z M8 12l3 3 5-6', 'edit' => 'm4 16 12-12 4 4L8 20H4v-4z M13 7l4 4',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16', 'dots' => 'M5 12h.01 M12 12h.01 M19 12h.01', 'external' => 'M14 3h7v7 M21 3l-9 9 M10 3H3v18h18v-7', 'filter' => 'M3 4h18l-7 8v7l-4 2v-9L3 4z',
        'copy' => 'M8 8h12v13H8z M16 8V3H3v13h5', 'phone' => 'M5 3h4l2 5-3 2a11 11 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2z',
        'cpu' => 'M7 7h10v10H7z M10 3v4 M14 3v4 M10 17v4 M14 17v4 M3 10h4 M3 14h4 M17 10h4 M17 14h4', 'up' => 'M12 21V9 M7 14l5-5 5 5',
    ];

    public static function svg(string $name, string $class = ''): string
    {
        $d = self::PATHS[$name] ?? self::PATHS['grid'];

        return '<svg class="ico '.e($class).'" viewBox="0 0 24 24" aria-hidden="true"><path d="'.$d.'"/></svg>';
    }
}
