<?php

namespace Drupal\entity_layout\Service;

/**
 * Class LayoutRenderer
 * @package Drupal\entity_layout\Service
 */
class LayoutRenderer {
  /**
   * @param $build
   *
   * @return mixed
   */
  public function entityViewPreRender(array $build) {
    ksm($build);

    return $build;
  }
}
