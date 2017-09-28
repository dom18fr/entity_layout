<?php

namespace Drupal\entity_layout\Plugin\Field\FieldType;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Field\Annotation\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'entity_layout_field_type' field type.
 *
 * @FieldType(
 *   id = "entity_layout_field_type",
 *   label = @Translation("Entity layout"),
 *   description = @Translation("Field type used to store layout instances and linked field items in its region."),
 *   default_widget = "entity_layout_basic_field_widget",
 *   default_formatter = "entity_layout_field_formatter",
 * )
 */
class EntityLayoutFieldType extends FieldItemBase {

  /**
   * {@inheritdoc}
   * @throws \InvalidArgumentException
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['layout'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Layout plugin'))
      ->setRequired(TRUE);

    $properties['regions'] = DataDefinition::create('any')
      ->setLabel(new TranslatableMarkup('Region mapping'))
      ->setRequired(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'layout' => [
          'type' => 'text',
          'size' => 'tiny',
          'not null' => TRUE,
        ],
        'regions' => [
          'type' => 'blob',
          'size' => 'normal',
          'serialize' => TRUE,
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {

    return [];
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \InvalidArgumentException
   */
  public function isEmpty() {
    $layout = $this->get('layout')->getValue();
    return $layout === NULL || $layout === '';
  }

}
