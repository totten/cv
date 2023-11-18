<?php

namespace Civi\Cv\Util;

/**
 * Some cv commands allow two dimensions of parameters -- the dash parameters
 * (`-f`, `--force`) are instructions for how to process the command-line request.
 * The plus parameters (`+s foo`, `+select foo`) are shorthand for data updates.
 *
 * This is a base-class for separating out the plus parameters.
 */
abstract class AbstractPlusParser {

  public function parse($args, $defaults = []) {
    $state = '_TOP_';
    $params = $defaults;
    foreach ($args as $arg) {
      if ($state !== '_TOP_') {
        $this->applyOption($params, $state, $arg);
        $state = '_TOP_';
      }
      // Ex: 'foo=bar', 'fo.oo=bar', 'fo:oo=bar'
      elseif (preg_match('/^([a-zA-Z0-9_:\.]+)=(.*)/', $arg, $matches)) {
        [, $key, $value] = $matches;
        $params[$key] = $this->parseValueExpr($value);
      }
      // Ex: '+w', '+where'
      elseif (preg_match('/^\+([a-zA-Z0-9_]+)$/', $arg, $matches)) {
        $state = $matches[1];
      }
      // Ex: '+l=2', '+l:2'
      elseif (preg_match('/^\+([a-zA-Z0-9_]+)[:=](.*)/', $arg, $matches)) {
        [, $key, $expr] = $matches;
        $this->applyOption($params, $key, $expr);
      }
      // Ex: '{"foo": "bar"}'
      elseif (preg_match('/^\{.*\}$/', $arg)) {
        $params = array_merge($params, $this->parseJsonNoisily($arg));
      }
      else {
        throw new \RuntimeException("Unrecognized option format: $arg");
      }
    }
    return $params;
  }

  protected static function mergeInto(&$params, $key, $values) {
    if (!isset($params[$key])) {
      $params[$key] = [];
    }
    $params[$key] = array_merge($params[$key], $values);
  }

  protected static function appendInto(&$params, $key, $values) {
    if (!isset($params[$key])) {
      $params[$key] = [];
    }
    $params[$key][] = $values;
  }

  /**
   * @param string $arg
   * @return mixed
   */
  protected function parseJsonNoisily($arg) {
    $values = json_decode($arg, 1);
    if ($values === NULL) {
      throw new \RuntimeException("Failed to parse JSON: $values");
    }
    return $values;
  }

  /**
   * @param $expr
   * @return mixed
   */
  protected function parseValueExpr($expr) {
    if ($expr !== '' && strpos('{["\'', $expr[0]) !== FALSE) {
      return $this->parseJsonNoisily($expr);
    }
    else {
      return $expr;
    }
  }

  /**
   * Parse a key-path expression.
   *
   * This is similar to the left-hand-side of a Javascript assignment -- with some mix
   * of dots, brackets, and quotes.
   *
   * @param string $expr
   *   Ex: 'foo.bar.whiz.bang'
   *   Ex: 'foo["bar"]["whiz"]["bang"]'
   *   Ex: 'foo["dot.dot.dot"].bar'
   *   Ex: 'foo["\"quote\""].bar
   * @return array
   *   Ex: ['foo', 'bar', 'whiz', 'bang']
   *   Ex: ['foo', 'dot.dot.dot', 'bar']
   *   Ex: ['foo', '"quote"', bar]
   */
  public function parseKey($expr): array {
    $buffer = '.' . $expr;
    $parts = [];
    while ($buffer !== '') {
      // Grab from front: .foobar
      if (preg_match(';^\.([a-zA-Z0-9_\-]*)(.*)$;', $buffer, $m)) {
        $parts[] = $m[1];
        $buffer = $m[2];
      }
      // Grab from front: ["foo\nbar"]
      // elseif (preg_match(';^\[(\"(?:\\.|[^\"\\])*\")\](.*);', $buffer, $m)) {
      elseif (preg_match(';^\[(\"((\\\.)|[^\\\])*\")\](.*);U', $buffer, $m)) {
        $parts[] = json_decode($m[1]);
        $buffer = mb_substr($buffer, 2 + mb_strlen($m[1]));
      }
      // Grab from front [foo.bar]
      // elseif (preg_match(';^\[([^\]]*)\](.*);U', $buffer, $m)) {
      //   $parts[] = $m[1];
      //   $buffer = $m[2];
      // }
      else {
        throw new \RuntimeException("Malformed key-expression: $expr");
      }
    }
    return $parts;
  }

  /**
   * Parse a string that contains a list of elements.
   *
   * @param string $expr
   *    Ex: '["one","two"]' (Multiple values, JSON)
   *    Ex: '"one"' (One value, JSON)
   *    Ex: 'one' (Bare string, explodable)
   * @param string|NULL $delim
   * @return array
   */
  public function parseList($expr, ?string $delim = ','): array {
    if ($expr === '') {
      return [];
    }
    switch ($expr[0]) {
      case '[':
        return $this->parseJsonNoisily($expr);

      case '"':
        return [$this->parseJsonNoisily($expr)];

      default:
        return ($delim === NULL) ? [$expr] : explode($delim, $expr);
    }
  }

  /**
   * @param $expr
   *   Ex: 'a+=z'
   *   Ex: 'a.b.c={"z":1}'
   * @return array
   *   Ex: ['a', '+=', 'z']
   *   Ex: ['a.b.c', '=', [z => 1]]
   */
  public function parseRichOp($expr) {
    if (preg_match('/^!([a-zA-Z0-9_:\.]+)\s*$/i', $expr, $matches)) {
      return [$this->parseKey($matches[1]), '!'];
    }
    elseif (preg_match('/^([a-zA-Z0-9_:\.]+)\s*(=|\[\]=|\+=|-=)\s*(.*)$/i', $expr, $matches)) {
      if (!empty($matches[3])) {
        return [$this->parseKey($matches[1]), strtoupper(trim($matches[2])), $this->parseValueExpr(trim($matches[3]))];
      }
      else {
        return [$this->parseKey($matches[1]), strtoupper($matches[2])];
      }
    }
    else {
      throw new \RuntimeException("Error parsing expression: $expr");
    }
  }

  /**
   * @param array $params
   * @param string $type
   *   The name of the plus parameter.
   *   Ex: "+s foo" ==> "s"
   *   Ex: "+where foo=bar" ==> "where"
   * @param string $expr
   *   Ex: "+s foo" ==> "foo"
   *   Ex: "+where foo=bar" ==> "foo=bar"
   *
   * @return mixed
   */
  abstract protected function applyOption(array &$params, string $type, string $expr): void;

  public function parseAssignment($expr) {
    if (preg_match('/^([a-zA-Z0-9_:\.]+)\s*=\s*(.*)$/', $expr, $matches)) {
      return [$matches[1] => $this->parseValueExpr($matches[2])];
    }
    else {
      throw new \RuntimeException("Error parsing \"value\": $expr");
    }
  }

  public function parseWhere($expr) {
    if (preg_match('/^([a-zA-Z0-9_:\.]+)\s*(\<=|\>=|=|!=|\<|\>|IS NULL|IS NOT NULL|IS EMPTY|IS NOT EMPTY|LIKE|NOT LIKE|IN|NOT IN)\s*(.*)$/i', $expr, $matches)) {
      if (!empty($matches[3])) {
        return [$matches[1], strtoupper(trim($matches[2])), $this->parseValueExpr(trim($matches[3]))];
      }
      else {
        return [$matches[1], strtoupper($matches[2])];
      }
    }
    else {
      throw new \RuntimeException("Error parsing \"where\": $expr");
    }
  }

}
