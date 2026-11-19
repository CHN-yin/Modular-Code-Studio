<?php

define('ABSPATH', __DIR__);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/class-css.php';
require __DIR__ . '/../includes/class-bundler.php';
define('MCS\CodeModules\DIR', dirname(__DIR__) . '/');

function wp_json_encode($value) {
    return json_encode($value, JSON_THROW_ON_ERROR);
}

function wp_parse_url(...$args) {
    return parse_url(...$args);
}

$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(MCS\CodeModules\Bundler::compile($data['id'], $data['modules'], $data['base']), JSON_THROW_ON_ERROR);
