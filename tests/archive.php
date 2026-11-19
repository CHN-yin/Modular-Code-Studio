<?php

$path = $argv[1] ?? dirname(__DIR__, 2) . '/output/modular-code-studio.zip';
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
    throw new RuntimeException('Could not open or validate the release ZIP.');
}
$root = 'modular-code-studio/';
$files = $chunks = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->statIndex($i);
    $name = $entry['name'];
    if (!str_starts_with($name, $root) || isset($files[$name]) || str_contains($name, '\\')
        || preg_match('~(?:^|/)(?:\.\.|node_modules|\.npm-cache|tests)(?:/|$)~', $name)) {
        throw new RuntimeException('Unexpected ZIP entry: ' . $name);
    }
    $contents = $zip->getFromIndex($i);
    if ($contents === false || strlen($contents) !== $entry['size'] || crc32($contents) !== $entry['crc']) {
        throw new RuntimeException('Corrupt ZIP entry: ' . $name);
    }
    $files[$name] = true;
    if (preg_match('~^modular-code-studio/build/\d+\.js$~', $name)) {
        $chunks[] = $name;
    }
}
foreach ([
    'modular-code-studio.php', 'vendor/autoload.php', 'composer.lock', 'package-lock.json',
    'build/code-module/block.json', 'build/code-module/index.asset.php', 'build/code-module/index.js',
    'build/code-module/index.css', 'build/mcs-code-editor.js', 'build/mcs-format.js',
    'build/asset-sidebar/index.js', 'build/asset-sidebar/index.asset.php', 'webpack.config.js',
    'src/code-module/block.json', 'runtime/modules.js', 'runtime/preview.js', 'includes/class-sources.php',
    'README.md', 'TEST-REPORT.md', 'CHANGELOG.md', 'LICENSE', 'licenses/prettier.txt',
] as $required) {
    if (!isset($files[$root . $required])) {
        throw new RuntimeException('Missing production file: ' . $required);
    }
}
if (count($chunks) < 2) {
    throw new RuntimeException('The local editor and formatter vendor chunks are missing.');
}
$zip->close();
echo count($files) . ' ZIP entries passed layout, decompression and CRC validation.' . PHP_EOL;
