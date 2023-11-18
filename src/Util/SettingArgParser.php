<?php

namespace Civi\Cv\Util;

class SettingArgParser extends AbstractPlusParser {

  /**
   * @var \Civi\Core\SettingsBag
   */
  protected $settingsBag;

  /**
   * @var array
   */
  protected $settingsMeta;

  protected $pathDelimiter = '.';

  /**
   * @param \Civi\Core\SettingsBag $settingsBag
   * @param array $settingsMeta
   *   List of fields supported by this settings bag.
   */
  public function __construct(\Civi\Core\SettingsBag $settingsBag, array $settingsMeta) {
    $this->settingsBag = $settingsBag;
    $this->settingsMeta = $settingsMeta;
  }

  /**
   * @param array $params
   * @param string $type
   * @param string $expr
   *
   * @return mixed
   */
  protected function applyOption(array &$params, string $type, string $expr): void {
    $aliases = [
      'o' => 'object',
      'l' => 'list',
    ];
    $type = $aliases[$type] ?? $type;

    [$keyParts, $op, $value] = $this->parseRichOp($expr);
    $this->includeBaseSetting($params, $keyParts[0]);

    switch ("$type $op") {
      // Assign value at specific location, eg
      // +object mailing_backend.outBoundOption=2
      // +list contact_reference_options.0=3
      case 'list =':
      case 'object =':
        ArrayUtil::pathSet($params, $keyParts, $value);
        break;

      // Add value to a list, eg
      // +list contact_reference_options[]=3
      // +list contact_reference_options+=3
      case 'list +=':
      case 'list []=':
        $fullValue = \CRM_Utils_Array::pathGet($params, $keyParts, []);
        if (!in_array($value, $fullValue)) {
          $fullValue[] = $value;
        }
        ArrayUtil::pathSet($params, $keyParts, $fullValue);
        break;

      // Remove value from a list, eg
      // +list contact_reference_options-=3
      case 'list -=':
        $fullValue = \CRM_Utils_Array::pathGet($params, $keyParts, []);
        $fullValue = array_values(array_diff($fullValue, [$value]));
        ArrayUtil::pathSet($params, $keyParts, $fullValue);
        break;

      // Remove offset from a list
      // +list !contact_reference_options.0
      case 'list !':
        if (count($keyParts) <= 1) {
          throw new \RuntimeException("Expression \"$expr\" should specify which item to remove.");
        }
        $parent = $keyParts;
        $offset = array_pop($parent);
        $arrayValue = \CRM_Utils_Array::pathGet($params, $parent);
        unset($arrayValue[$offset]);
        $arrayValue = array_values($arrayValue);
        \CRM_Utils_Array::pathSet($params, $parent, $arrayValue);
        break;

      // Remove key from object, eg
      // +object !mailing_backend.outBoundOption
      case 'object !':
        if (count($keyParts) <= 1) {
          throw new \RuntimeException("Expression \"$expr\" should specify which property to remove.");
        }
        \CRM_Utils_Array::pathUnset($params, $keyParts);
        break;

      default:
        throw new \RuntimeException("Unrecognized option: +$type");
    }
  }

  protected function includeBaseSetting(array &$params, string $settingKey) {
    [$encode, $decode] = SettingCodec::codec($this->settingsMeta, $settingKey);
    if (!isset($params[$settingKey])) {
      $params[$settingKey] = $decode($this->settingsBag->get($settingKey));
    }
  }

  protected function parseValueExpr($expr) {
    if ($expr !== '' && strpos('{["\'', $expr[0]) !== FALSE) {
      return $this->parseJsonNoisily($expr);
    }
    else {
      return $this->castNumberSoftly($expr);
    }
  }

  private function castNumberSoftly($value) {
    if (is_string($value) && preg_match(';^\d+$;', $value)) {
      return (int) $value;
    }
    elseif (is_string($value) && preg_match(';^\d+\.\d+$;', $value)) {
      return (float) $value;
    }
    return $value;
  }

}
