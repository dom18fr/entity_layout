<?php

namespace Drupal\entity_layout\Plugin\Field\FieldFormatter;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Field\Annotation\FieldFormatter;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'entity_layout_field_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "entity_layout_field_formatter",
 *   label = @Translation("Entity Layout"),
 *   field_types = {
 *     "entity_layout_field_type"
 *   }
 * )
 */
class EntityLayoutFieldFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];

    foreach ($items as $delta => $item) {
      // Render each element as markup.
      $element[$delta] = [
        '#type' => 'entity_layout',
        '#layout_id' => $item->layout,
        '#regions' => $item->regions,
      ];
    }

    ksm($element);

    return $element;
  }

}
