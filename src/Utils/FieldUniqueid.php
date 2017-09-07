<?php
namespace Drupal\entity_layout\Utils;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldDefinitionInterface;

class FieldUniqueId {
  /**
   * Generate a unique id based on the current field instance to prevent ajax
   * replacement collisions when several layout fields exists in the current
   * entity form.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   * @param $key
   * @return string
   */
  public static function getUniqueId(FieldDefinitionInterface $field_definition, $key) {
    // We use Html::getId() instead of Html::getUniqueId() to get it match
    // over ajax rebuilding process.
    return Html::getId(
      implode(
        '-',
        [
          'entity-layout',
          $field_definition->getTargetEntityTypeId(),
          $field_definition->getTargetBundle(),
          $field_definition->getName(),
          $key,
        ]
      )
    );
  }
}
