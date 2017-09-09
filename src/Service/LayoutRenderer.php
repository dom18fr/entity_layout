<?php

namespace Drupal\entity_layout\Service;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Render\Element;

/**
 * Class LayoutRenderer
 * @package Drupal\entity_layout\Service
 */
class LayoutRenderer implements LayoutRendererInterface {

  /** @var array $themeRegistry */
  protected $themeRegistry;

  /**
   * LayoutRenderer constructor.
   */
  public function __construct() {
    $this->themeRegistry = theme_get_registry();
  }

  /**
   * See entity_layout_entity_view_alter()
   *
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
      $build['#entity_layout']['weight'] = $build[$name]['#weight'];
      // Store the name of the component that contain entity_layout data
      foreach(Element::getVisibleChildren($build[$name]) as $delta) {
        $build['#entity_layout']['layouts'][$delta] = $build[$name][$delta];
      }
      unset($build[$name]);
    }
  }

  /**
   * See entity_layout_preprocess()
   *
   * @param array $variables
   * @param string $hook
   */
  public function preprocess(array &$variables, $hook) {
    // Get information about current hook from registry
    $theme_info = $this->themeRegistry[$hook];
    // Get original render array and ensure we work on an entity that actually
    // contain layout informations
    /** @noinspection ReferenceMismatchInspection */
    if (
      array_key_exists('render element', $theme_info)
      && array_key_exists($theme_info['render element'], $variables)
      && array_key_exists(
        '#entity_layout',
        $variables[$theme_info['render element']]
      )
    ) {
      // Grab a reference to the original render array
      $build = &$variables[$theme_info['render element']];
      // Store layout info then cleanup the render array
      $layout_info = $build['#entity_layout'];
      unset($build['#entity_layout']);
      // The content part is the pone actually renderd, so work on it
      $content = &$variables['content'];
      $this->performNesting($content, $layout_info);
    }
  }

  /**
   * @param array $content
   * @param array $layout_info
   */
  protected function performNesting(array &$content, array $layout_info) {
    ksm($layout_info);
    $content['yolo'] = [
      '#markup' => '<div>Here is a yolo content, built in a preprocess context</div>',
    ];
  }
}
