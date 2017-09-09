<?php

namespace Drupal\entity_layout\Service;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Utility\ThemeRegistry;

/**
 * Class LayoutRenderer
 * @package Drupal\entity_layout\Service
 */
class LayoutRenderer implements LayoutRendererInterface {

  /** @var ThemeRegistry $themeRegistry */
  protected $themeRegistry;

  /**
   * LayoutRenderer constructor.
   *
   * @param ThemeRegistry $theme_registry
   */
  public function __construct(ThemeRegistry $theme_registry) {
    $this->themeRegistry = $theme_registry;
  }

  /**
   * @param array $build
   * @param EntityViewDisplayInterface $display
   */
  public function entityViewAlter(array &$build, EntityViewDisplayInterface $display) {
    // Iterate over all displayable components to find an entity layout formatter.
    foreach($display->getComponents() as $name => $component) {
      if (
        false === array_key_exists('type', $component)
        || 'entity_layout_field_formatter' !== $component['type']
      ) {
        continue;
      }
      // Store the name of the component that contain entity_layout data
      foreach(Element::getVisibleChildren($build[$name]) as $layout_delta) {
        $build['#entity_layout'][$layout_delta] = $build[$name][$layout_delta];
      }
      unset($build[$name]);
    }
  }

  /**
   * @param array $variables
   * @param string $hook
   */
  public function preprocess(array &$variables, $hook) {
    ksm($this->themeRegistry->get($hook));
    // get info from hook_theme to know the render element key, then alter its content
  }
}
