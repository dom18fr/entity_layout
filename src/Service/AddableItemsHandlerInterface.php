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

  public function getAddableItemsElement(FieldableEntityInterface $entity, array $used, $addable_item_id);

  public function getUsedAddableItems(FieldItemListInterface $items, FormStateInterface $form_state);

  public function grabAddableItemsElement(array $trigger, array $form, $id);
  
  public function processEntityForm(array &$form, FormStateInterface $form_state);
}
