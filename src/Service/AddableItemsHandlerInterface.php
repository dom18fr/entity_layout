<?php

namespace Drupal\entity_layout\Service;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Interface AddableItemsHandlerInterface
 * @package Drupal\entity_layout\Service
 */
interface AddableItemsHandlerInterface {
  
  /**
   * @param FieldableEntityInterface $entity
   * @param string array $used
   * @param string $addable_item_id
   * @param FormStateInterface $form_state
   *
   * @return array
   */
  public function getAddableItemsElement(FieldableEntityInterface $entity, array $used, $addable_item_id, FormStateInterface $form_state);
  
  /**
   * @param FieldItemListInterface $items
   * @param FormStateInterface $form_state
   *
   * @return array|null
   */
  public function getUsedAddableItems(FieldItemListInterface $items, FormStateInterface $form_state);
  
  /**
   * @param array $trigger
   * @param array $form
   * @param string $id
   *
   * @return array|null
   */
  public function grabAddableItemsElement(array $trigger, array $form, $id);
  
  /**
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function processEntityForm(array &$form, FormStateInterface $form_state);
}
