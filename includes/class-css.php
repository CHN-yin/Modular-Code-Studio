<?php

namespace MCS\CodeModules;

use Wikimedia\CSS\Objects\AtRule;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\CSSFunction;
use Wikimedia\CSS\Objects\SimpleBlock;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Parser\Parser;
use Wikimedia\CSS\Util;

defined('ABSPATH') || exit;

final class Css {
    public static function compile(string $source, string $base): array {
        $parser = Parser::newFromString($source);
        $rules = $parser->parseStylesheet()->getRuleList();
        $out = ['prelude' => [], 'namespaces' => [], 'body' => [], 'selectors' => [], 'errors' => []];
        $leading_layers = [];
        foreach ($parser->getParseErrors() as [$kind, $line, $column]) {
            $out['errors'][] = sprintf('%s (%d:%d)', $kind, $line, $column);
        }
        foreach ($rules as $rule) {
            $name = $rule instanceof AtRule ? strtolower($rule->getName()) : '';
            if ($name === 'charset') {
                continue;
            }
            self::rebase($rule->getPrelude(), $base, $name === 'import');
            $block = $rule->getBlock();
            if ($block) {
                self::rebase($block->getValue(), $base);
            }
            if (!$block && $name === 'import') {
                array_push($out['prelude'], ...$leading_layers);
                $leading_layers = [];
                $out['prelude'][] = (string) $rule;
            } elseif ($name === 'namespace' && !$block) {
                $out['namespaces'][] = (string) $rule;
            } elseif ($name === 'layer' && !$block && !$out['body']) {
                $leading_layers[] = (string) $rule;
            } else {
                array_push($out['body'], ...$leading_layers);
                $leading_layers = [];
                $out['body'][] = (string) $rule;
            }
            if ($block && $name === '') {
                $parents = self::selectors($rule->getPrelude(), []);
                $out['selectors'] = array_merge($out['selectors'], $parents);
                self::nested($block->getValue(), $parents, $out['selectors']);
            } elseif ($block && self::group($name)) {
                self::nested($block->getValue(), [], $out['selectors']);
            }
        }
        array_push($out['body'], ...$leading_layers);
        return $out;
    }

    private static function rebase(ComponentValueList $list, string $base, bool $import = false): void {
        foreach ($list as $i => $node) {
            if ($node instanceof Token && $node->type() === Token::T_WHITESPACE) {
                continue;
            }
            if ($node instanceof Token && ($node->type() === Token::T_URL || ($import && $node->type() === Token::T_STRING))) {
                $list[$i] = new Token($node->type(), self::resolve($node->value(), $base));
            } elseif ($node instanceof CSSFunction) {
                self::rebase($node->getValue(), $base, strtolower($node->getName()) === 'url');
            } elseif ($node instanceof SimpleBlock) {
                self::rebase($node->getValue(), $base);
            }
            $import = false;
        }
    }

    public static function resolve(string $value, string $base): string {
        if ($value === '' || preg_match('~^(?:[a-z][a-z0-9+.\-]*:|//|#)~i', $value)) {
            return $value;
        }
        $origin = wp_parse_url($base);
        if (empty($origin['scheme']) || empty($origin['host'])) {
            return $value;
        }
        $root = $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
        $page_path = $origin['path'] ?? '/';
        if (str_starts_with($value, '?')) {
            return $root . $page_path . $value;
        }
        $path_length = strcspn($value, '?#');
        $path = substr($value, 0, $path_length);
        $path = str_starts_with($path, '/') ? $path : substr($page_path, 0, (int) strrpos($page_path, '/') + 1) . $path;
        $segments = [];
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $trailing = str_ends_with($path, '/.') || str_ends_with($path, '/..');
        return $root . '/' . implode('/', $segments) . ($trailing && $segments ? '/' : '') . substr($value, $path_length);
    }

    private static function group(string $name): bool {
        return in_array($name, ['media', 'supports', 'container', 'layer', 'scope', 'starting-style', 'document'], true);
    }

    private static function nested(ComponentValueList $list, array $parents, array &$selectors): void {
        $prelude = [];
        foreach ($list as $node) {
            if ($node instanceof Token && $node->type() === Token::T_SEMICOLON) {
                $prelude = [];
                continue;
            }
            if (!$node instanceof SimpleBlock || $node->getStartTokenType() !== Token::T_LEFT_BRACE) {
                $prelude[] = $node;
                continue;
            }
            $tokens = new ComponentValueList($prelude);
            $first = Util::findFirstNonWhitespace($tokens);
            if ($first instanceof Token && $first->type() === Token::T_AT_KEYWORD) {
                if (self::group(strtolower($first->value()))) {
                    self::nested($node->getValue(), $parents, $selectors);
                }
            } elseif ($first && !str_starts_with((string) $first, '--')) {
                $next = self::selectors($tokens, $parents);
                $selectors = array_merge($selectors, $next);
                self::nested($node->getValue(), $next, $selectors);
            }
            $prelude = [];
        }
    }

    private static function selectors(ComponentValueList $list, array $parents): array {
        $groups = [[]];
        foreach ($list as $node) {
            if ($node instanceof Token && $node->type() === Token::T_COMMA) {
                $groups[] = [];
            } else {
                $groups[count($groups) - 1][] = $node;
            }
        }
        $result = [];
        $scope = count($parents) > 1 ? ':is(' . implode(', ', $parents) . ')' : ($parents[0] ?? '');
        foreach ($groups as $nodes) {
            $text = trim(Util::stringify($nodes));
            if ($text === '') {
                continue;
            }
            if ($scope !== '') {
                $changed = false;
                $text = trim(self::expand_nesting($nodes, $scope, $changed));
                if (!$changed) {
                    $text = $scope . ' ' . $text;
                }
            }
            $result[] = $text;
        }
        return $result;
    }

    private static function expand_nesting(iterable $nodes, string $scope, bool &$changed): string {
        $text = '';
        foreach ($nodes as $node) {
            if ($node instanceof Token && $node->type() === Token::T_DELIM && $node->value() === '&') {
                $text .= $scope;
                $changed = true;
            } elseif ($node instanceof CSSFunction) {
                $text .= $node->getName() . '(' . self::expand_nesting($node->getValue(), $scope, $changed) . ')';
            } else {
                $text .= (string) $node;
            }
        }
        return $text;
    }
}
