<?php

namespace Drupal\entity_layout\Plugin\Field\FieldFormatter;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Field\Annotation\FieldFormatter;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

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
   * @param array $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);
    $form['display_unused'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display unused elements'),
      '#default_value' => (bool) $this->getSetting('display_unused'),
    ];

    return $form;
  }

  public static function defaultSettings() {
    return [
      'display_unused' => true,
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $display_unused = $this->getSetting('display_unused') ? $this->t('yes') : $this->t('No');
    return [
      '#markup' => $this->t('Display unused elements') . ' : ' . $display_unused,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [
      '#display_unused' => (bool) $this->getSetting('display_unused'),
    ];
    foreach ($items as $delta => $item) {
      // Render each element as markup.
      $element[$delta] = [
        '#type' => 'entity_layout',
        '#layout_id' => $item->layout,
        '#regions' => $item->regions,
      ];
    }

    return $element;
  }

}
