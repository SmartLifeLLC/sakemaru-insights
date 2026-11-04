<?php

namespace App\Monitoring\Advisories;

/**
 * 導入環境の把握（照合用）。owner ノードで実行時に 1 回だけ読む。
 * - Composer パッケージと版（composer.lock）
 * - Ubuntu のリリース名（/etc/os-release）と導入 deb パッケージ（dpkg-query）
 * - PHP の版
 * 外部へ送るのはリリース名・製品名だけで、パッケージ一覧は送らない。
 */
class Environment
{
    /** @var array<string,string>|null package => version */
    private ?array $composer = null;

    /** @var array<string,string>|null package => version */
    private ?array $debs = null;

    private ?string $ubuntuCodename = null;

    private bool $ubuntuRead = false;

    /** @param array<string,string>|null $composer テスト用の差し替え */
    public function __construct(?array $composer = null, ?array $debs = null, ?string $ubuntuCodename = null)
    {
        $this->composer = $composer;
        $this->debs = $debs;
        if ($ubuntuCodename !== null) {
            $this->ubuntuCodename = $ubuntuCodename;
            $this->ubuntuRead = true;
        }
    }

    /** @return array<string,string> */
    public function composerPackages(): array
    {
        if ($this->composer !== null) {
            return $this->composer;
        }
        $this->composer = [];
        $path = (string) config('monitoring.advisories.composer_lock');
        if ($path !== '' && is_readable($path)) {
            $lock = json_decode((string) file_get_contents($path), true) ?: [];
            foreach (array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])) as $p) {
                if (isset($p['name'], $p['version'])) {
                    $this->composer[strtolower($p['name'])] = ltrim((string) $p['version'], 'v');
                }
            }
        }

        return $this->composer;
    }

    public function phpVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;
    }

    public function ubuntuCodename(): ?string
    {
        if (! $this->ubuntuRead) {
            $this->ubuntuRead = true;
            if (is_readable('/etc/os-release')) {
                $vars = parse_ini_string((string) file_get_contents('/etc/os-release')) ?: [];
                if (strtolower((string) ($vars['ID'] ?? '')) === 'ubuntu') {
                    $this->ubuntuCodename = $vars['VERSION_CODENAME'] ?? null;
                }
            }
        }

        return $this->ubuntuCodename;
    }

    /** @return array<string,string> deb パッケージ => 版（Ubuntu 以外や dpkg が無い環境では空） */
    public function debPackages(): array
    {
        if ($this->debs !== null) {
            return $this->debs;
        }
        $this->debs = [];
        if ($this->ubuntuCodename() === null || ! is_executable('/usr/bin/dpkg-query')) {
            return $this->debs;
        }
        $out = @shell_exec("/usr/bin/dpkg-query -W -f='\${Package} \${Version}\\n' 2>/dev/null");
        foreach (explode("\n", (string) $out) as $line) {
            $parts = explode(' ', trim($line), 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $this->debs[$parts[0]] = $parts[1];
            }
        }

        return $this->debs;
    }

    /** @return list<string> deb パッケージ名が導入されていれば、それに対応する製品キー */
    public function productsForDeb(string $package): array
    {
        $keys = [];
        foreach ((array) config('monitoring.advisories.products') as $key => $p) {
            foreach ((array) ($p['deb'] ?? []) as $prefix) {
                if ($package === $prefix || str_starts_with($package, $prefix)) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }

    /** @return list<string> Composer パッケージ名に対応する製品キー（未定義のパッケージは 'composer'） */
    public function productsForComposer(string $package): array
    {
        $keys = [];
        foreach ((array) config('monitoring.advisories.products') as $key => $p) {
            if (in_array(strtolower($package), array_map('strtolower', (array) ($p['composer'] ?? [])), true)) {
                $keys[] = $key;
            }
        }

        return $keys ?: ['composer'];
    }
}
