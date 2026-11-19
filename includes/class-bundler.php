<?php

namespace MCS\CodeModules;

use Peast\Peast;
use Throwable;

defined('ABSPATH') || exit;

final class Bundler {
    public static function compile(int|string $id, array $modules, string $base, string $only = 'all'): array {
        $output = [
            'css' => '', 'js' => '', 'dependencies' => [], 'selectors' => [],
            'counts' => ['all' => count($modules), 'html' => 0, 'css' => 0, 'js' => 0],
            'issues' => ['css' => [], 'js' => []],
        ];
        $css = ['prelude' => [], 'namespaces' => [], 'body' => []];
        $scripts = [];
        foreach ($modules as $module) {
            foreach (['html' => 'enableHtml', 'css' => 'enableCss', 'js' => 'enableJs'] as $kind => $flag) {
                if ($module[$flag] && trim($module[$kind]) !== '') {
                    $output['counts'][$kind]++;
                }
            }
            $label = $module['moduleName'] ?: $module['key'];
            if (in_array($only, ['all', 'css'], true) && $module['enableCss'] && trim($module['css']) !== '') {
                try {
                    $sheet = Css::compile($module['css'], $base);
                    foreach ($css as $part => &$rules) {
                        array_push($rules, ...$sheet[$part]);
                    }
                    unset($rules);
                    array_push($output['selectors'], ...$sheet['selectors']);
                    foreach ($sheet['errors'] as $error) {
                        $output['issues']['css'][] = [$label, $error];
                    }
                } catch (Throwable $error) {
                    $output['issues']['css'][] = [$label, $error->getMessage()];
                }
            }
            if (in_array($only, ['all', 'js'], true) && $module['enableJs'] && trim($module['js']) !== '') {
                $function = "function(window, document, root, instanceId) {\n" . $module['js'] . "\n}";
                try {
                    Peast::latest('(' . $function . ');')->parse();
                } catch (Throwable $error) {
                    $output['issues']['js'][] = [$label, $error->getMessage()];
                    continue;
                }
                $scripts[] = sprintf(
                    "run(%s, %s, %s, %s);\n",
                    wp_json_encode($module['key']),
                    wp_json_encode($module['rootSelector']),
                    $module['wrapper'] ? 'true' : 'false',
                    $function
                );
                $handles = preg_split('/[\s,]+/', $module['dependencies'], -1, PREG_SPLIT_NO_EMPTY);
                array_push($output['dependencies'], ...array_filter($handles, static fn($h) => preg_match('/^[a-zA-Z0-9_.-]+$/D', $h)));
            }
        }
        if ($css['body'] || $css['prelude'] || $css['namespaces']) {
            $output['css'] = implode("\n", array_merge($css['prelude'], array_unique($css['namespaces']), $css['body'])) . "\n";
        }
        if ($scripts) {
            $runtime = file_get_contents(DIR . 'runtime/modules.js');
            $output['js'] = ";(function(window, document, pageId) {\n" . $runtime . "\n" . implode('', $scripts)
                . "})(window, document, " . wp_json_encode($id) . ");\n";
        }
        $output['dependencies'] = array_values(array_unique($output['dependencies']));
        $output['selectors'] = array_values(array_unique($output['selectors']));
        return $output;
    }
}
