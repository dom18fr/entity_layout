<?php

namespace Drupal\entity_layout\Service;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Interface AddableItemsHandlerInterface
 * @package Drupal\entity_layout\Service
 */
interface AddableItemsHandlerInterface {

  public function getAddableItemsElement(FieldableEntityInterface $entity, array $used, $addable_item_id);

}
