<?php
namespace Civi\Cv\Util;

/**
 * @group std
 * @group util
 */
class AbstractPlusParserTest extends \PHPUnit\Framework\TestCase {

  public function createExample() {
    return new class extends AbstractPlusParser {

      protected function applyOption(array &$params, string $type, string $expr): void {
      }

    };
  }

  public function getKeyExprs(): array {
    return [
      ['foo', ['foo']],
      ['foo.bar', ['foo', 'bar']],
      ['foo["bar"]', ['foo', 'bar']],
      ['foo.bar.', ['foo', 'bar', '']],
      ['foo["bar"]["whiz"]', ['foo', 'bar', 'whiz']],
      ['foo.bar.whiz.bang', ['foo', 'bar', 'whiz', 'bang']],
      ['foo["bar"]["whiz"]["bang"]', ['foo', 'bar', 'whiz', 'bang']],
      //x ['foo[bar][whiz][bang]', ['foo', 'bar', 'whiz', 'bang']],
      ['foo["bar"].whiz.bang', ['foo', 'bar', 'whiz', 'bang']],
      //x ['foo[bar].whiz.bang', ['foo', 'bar', 'whiz', 'bang']],
      ['foo["dot.dot"].bar', ['foo', 'dot.dot', 'bar']],
      //x ['foo[dot.dot].bar', ['foo', 'dot.dot', 'bar']],
      ['foo["whiz\nbang"].bar', ['foo', "whiz\nbang", 'bar']],
      ['foo["whiz\"bang"].bar', ['foo', 'whiz"bang', 'bar']],
    ];
  }

  /**
   * @param string $input
   * @param array $expected
   * @return void
   * @dataProvider getKeyExprs
   */
  public function testParseKey(string $input, array $expected) {
    $actual = $this->createExample()->parseKey($input);
    $this->assertEquals($expected, $actual);
  }

  public function getListExprs(): array {
    return [
      ['abc', ['abc']],
      ['abc,def', ['abc', 'def']],
      ['"abc"', ['abc']],
      ['[1,2,3]', [1, 2, 3]],
      ['["a b","c d"]', ['a b', 'c d']],
      ['["ab\"],cd","ef"]', ["ab\"],cd", "ef"]],
    ];
  }

  /**
   * @param string $input
   * @param array $expected
   * @return void
   * @dataProvider getListExprs
   */
  public function testParseList(string $input, array $expected) {
    $actual = $this->createExample()->parseList($input);
    $this->assertEquals($expected, $actual);
  }

  public function getRichOps(): array {
    return [
      ['ab=cd', ['ab', '=', 'cd']],
      ['ab+=cd', ['ab', '+=', 'cd']],
      ['ab.cd+=ef.gh', ['ab.cd', '+=', 'ef.gh']],
      ['ab.cd-=ef.gh', ['ab.cd', '-=', 'ef.gh']],
      ['ab.cd[]=x', ['ab.cd', '[]=', 'x']],
      ['obj={"k":"v"}', ['obj', '=', ['k' => 'v']]],
      ['arr=[123,"xyz"]', ['arr', '=', [123, "xyz"]]],
      ['!ab', ['ab', '!']],
      ['!ab.cd', ['ab.cd', '!']],
    ];
  }

  /**
   * @dataProvider getRichOps
   */
  public function testParseRichOp(string $input, array $expected) {
    $actual = $this->createExample()->parseRichOp($input);
    $this->assertEquals($expected, $actual);
  }

}
