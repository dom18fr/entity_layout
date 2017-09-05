<?php

namespace Drupal\entity_layout\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

class AddableItemsHandler implements AddableItemsHandlerInterface {

  /** @var EntityFieldManagerInterface $entityFieldManager */
  protected $entityFieldManager;

  /**
   * AddableItemsHandler constructor.
   * @param EntityFieldManagerInterface $entity_field_manager
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager) {
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * @param FieldableEntityInterface $entity
   * @param array $used
   * @param $addable_item_id
   *
   * @throws \LogicException
   * @throws \InvalidArgumentException
   *
   * @return array
   */
  public function getAddableItemsElement(FieldableEntityInterface $entity, array $used, $addable_item_id) {
    $components = $this->getRootComponents($entity);
    $component_list = [
      '#type' => 'radios',
      '#options' => [],
      '#options_details' => [],
    ];
    /** @var array $options */
    $options = &$component_list['#options'];
    $options_details = &$component_list['#options_details'];
    foreach ($components as $id => $component) {
      $options[$id] = $id;
      $options_details[$id] = [
        'id' => $id,
        'delta' => null,
      ];
      if ('field' === $component['type']) {
        $this->buildFieldItemsAddableItemsElement(
          $entity,
          $component,
          $options,
          $options_details
        );
      }
    }

    foreach ($options as $id => $label) {
      if (array_key_exists($id, $used)) {
        $component_list[$id] = [
          '#disabled' => true,
        ];
      }
    }

    return $component_list;
  }

  /**
   * @param FieldableEntityInterface $entity
   * @param array $component
   * @param array $options
   * @param array $options_details
   *
   * @throws \InvalidArgumentException
   */
  protected function buildFieldItemsAddableItemsElement(FieldableEntityInterface $entity, array $component, array &$options, array &$options_details) {
    $item_count = $entity->get($component['id'])->count();
    $cardinality = $entity->getFieldDefinition($component['id'])
      ->getFieldStorageDefinition()
      ->getCardinality();
    if (
      1 === $cardinality
      || $item_count < 2
    ) {
      return;
    }
    $delta = 0;
    while ($delta < $item_count) {
      $item_id = $component['id'] . ':' . $delta;
      $options[$item_id] = $item_id;
      $options_details[$item_id] = [
        'id' => $item_id,
        'delta' => $delta,
      ];
      $delta++;
    }
  }

  /**
   * @param FieldableEntityInterface $entity
   *
   * @return array
   * @throws \LogicException
   */
  protected function getRootComponents(FieldableEntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $field_definitions = $this->entityFieldManager->getFieldDefinitions(
      $entity_type,
      $bundle
    );
    $extra_fields = $this->entityFieldManager->getExtraFields(
      $entity_type,
      $bundle
    )['display'];
    $component_definitions = array_merge(
      $field_definitions,
      $extra_fields
    );
    $components = [];
    foreach ($component_definitions as $id => $definition) {
      $label = $id;
      $type = 'extrafield';
      if (false === is_array($definition)) {
        /** @var FieldDefinitionInterface $definition */
        if (
          false === $definition->isDisplayConfigurable('view')
          || 'entity_layout_field_type' === $definition->getType()
        ) {
          continue;
        }
        $type = 'field';
      } else {
        $label = $definition['label'];
      }
      $components[$id] = [
        'id' => $id,
        'label' => $label,
        'type' => $type,
      ];
    }

    return $components;
  }
}
