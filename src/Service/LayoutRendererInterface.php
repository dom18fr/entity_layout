<?php

namespace Drupal\entity_layout\Service;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;

/**
 * Interface LayoutRendererInterface
 * @package Drupal\entity_layout\Service
 */
interface LayoutRendererInterface {

  /**
   * @param array $build
   * @param EntityViewDisplayInterface $display
   */
  public function entityViewAlter(array &$build, EntityViewDisplayInterface $display);

  /**
   * @param array $variables
   * @param string $hook
   */
  public function preprocess(array &$variables, $hook);
}
