<?php

namespace MCS\CodeModules;

use RuntimeException;

defined('ABSPATH') || exit;

final class Storage {
    public const FOLDER = 'modular-code-studio';
    private const FILE_PATTERN = '/^page-(?:widget-)?[1-9][0-9]*\.([0-9a-f]{64}|[0-9a-f]{96})\.(css|js)$/D';

    public static function base(): array {
        $uploads = wp_get_upload_dir();
        return [
            'dir' => untrailingslashit($uploads['basedir']) . '/' . self::FOLDER,
            'url' => untrailingslashit($uploads['baseurl']) . '/' . self::FOLDER,
        ];
    }

    private static function page_dir(int|string $id): string {
        if (!preg_match('/^(?:widget-)?[1-9][0-9]*$/D', (string) $id)) {
            throw new RuntimeException('Invalid Modular Code Studio asset source.');
        }
        return self::base()['dir'] . '/page-' . $id;
    }

    private static function make_dir(string $dir): void {
        clearstatcache(true, $dir);
        if (is_link($dir) || (!is_dir($dir) && !wp_mkdir_p($dir))) {
            throw new RuntimeException('Could not open the Modular Code Studio uploads directory.');
        }
    }

    public static function initialize(): void {
        $base = self::base()['dir'];
        self::make_dir($base);
        $files = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "Options -Indexes\n<FilesMatch \"(?i)\\.(?:php[0-9]*|phtml|phar)(?:\\.|$)|(?:^\\.|manifest\\.json$)\">\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n</FilesMatch>\n",
            'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer>'
                . '<directoryBrowse enabled="false"/><security><requestFiltering><fileExtensions>'
                . '<add fileExtension=".php" allowed="false"/><add fileExtension=".phtml" allowed="false"/>'
                . '<add fileExtension=".phar" allowed="false"/></fileExtensions><hiddenSegments>'
                . '<add segment="manifest.json"/></hiddenSegments></requestFiltering></security></system.webServer></configuration>',
        ];
        foreach ($files as $name => $body) {
            if (!is_file($base . '/' . $name)) {
                self::atomic($base . '/' . $name, $body);
            }
        }
    }

    public static function locked(int|string $id, callable $operation) {
        self::initialize();
        $dir = self::page_dir($id);
        self::make_dir($dir);
        $lock_path = $dir . '/.lock';
        $lock = is_link($lock_path) ? false : @fopen($lock_path, 'c');
        if (!$lock) {
            throw new RuntimeException('Could not open the page asset lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Could not acquire the page asset lock.');
            }
            return $operation($dir);
        } finally {
            fclose($lock);
        }
    }

    public static function atomic(string $destination, string $body): void {
        if (is_link($destination)) {
            throw new RuntimeException('Refusing to replace a symbolic link in the asset directory.');
        }
        $temp = dirname($destination) . '/.mcs-' . bin2hex(random_bytes(12)) . '.tmp';
        $file = @fopen($temp, 'xb');
        if (!$file) {
            throw new RuntimeException('Could not create a temporary asset.');
        }
        try {
            $written = fwrite($file, $body);
            if ($written !== strlen($body) || !fflush($file)) {
                throw new RuntimeException('The asset could not be written completely.');
            }
            fclose($file);
            @chmod($temp, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
            if (!@rename($temp, $destination)) {
                throw new RuntimeException('Could not publish the asset atomically.');
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    public static function read(int|string $id): array {
        $dir = self::page_dir($id);
        $path = $dir . '/manifest.json';
        if (is_link(self::base()['dir']) || is_link($dir) || is_link($path) || !is_file($path)) {
            return [];
        }
        $manifest = json_decode((string) @file_get_contents($path), true);
        return is_array($manifest) && ($manifest['id'] ?? 0) === $id ? $manifest : [];
    }

    public static function bundle(int|string $id, string $kind, array $manifest): ?array {
        $entry = $manifest[$kind] ?? null;
        if (!$entry || !is_array($entry) || !isset($entry['file'], $entry['hash'], $entry['bytes'])) {
            return null;
        }
        $file = $entry['file'];
        $dir = self::page_dir($id);
        $expected = 'page-' . $id . '.' . $entry['hash'] . '.' . $kind;
        if ($file !== $expected || !preg_match(self::FILE_PATTERN, $file) || is_link($dir . '/' . $file) || !is_file($dir . '/' . $file)) {
            return null;
        }
        $entry['url'] = self::base()['url'] . '/page-' . $id . '/' . $file;
        return $entry;
    }

    public static function write_bundle(string $dir, int|string $id, string $kind, string $code): ?array {
        if ($code === '') {
            return null;
        }
        $hash = hash(Settings::get('hash'), $code);
        $file = 'page-' . $id . '.' . $hash . '.' . $kind;
        $path = $dir . '/' . $file;
        if (is_link($path) || !is_file($path) || filesize($path) !== strlen($code) || hash_file(Settings::get('hash'), $path) !== $hash) {
            self::atomic($path, $code);
        }
        return ['file' => $file, 'hash' => $hash, 'bytes' => strlen($code)];
    }

    public static function retire(int|string $id, array $previous, array $next): void {
        foreach (['css', 'js'] as $kind) {
            $old = self::bundle($id, $kind, $previous);
            if ($old && $old['file'] !== ($next[$kind]['file'] ?? null)) {
                @touch(self::page_dir($id) . '/' . $old['file']);
            }
        }
    }

    public static function cleanup(): int {
        $root = self::base()['dir'];
        if (!is_dir($root) || is_link($root)) {
            return 0;
        }
        $cutoff = time() - DAY_IN_SECONDS * Settings::get('retention');
        $total = 0;
        foreach (glob($root . '/page-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir) || !preg_match('/^page-((?:widget-)?[1-9][0-9]*)$/D', basename($dir), $match)) {
                continue;
            }
            $id = str_starts_with($match[1], 'widget-') ? $match[1] : (int) $match[1];
            $total += self::locked($id, static function (string $path) use ($id, $cutoff): int {
                $manifest = self::read($id);
                $live = [$manifest['css']['file'] ?? '', $manifest['js']['file'] ?? ''];
                $count = 0;
                foreach (scandir($path) ?: [] as $file) {
                    if (!preg_match(self::FILE_PATTERN, $file) && !preg_match('/^\.mcs-[0-9a-f]{24}\.tmp$/D', $file)) {
                        continue;
                    }
                    if (str_starts_with($file, 'page-') && !str_starts_with($file, 'page-' . $id . '.')) {
                        continue;
                    }
                    $full = $path . '/' . $file;
                    if (!in_array($file, $live, true) && !is_link($full) && is_file($full) && filemtime($full) < $cutoff && @unlink($full)) {
                        $count++;
                    }
                }
                return $count;
            });
        }
        return $total;
    }
}
