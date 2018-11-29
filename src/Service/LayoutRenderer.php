<?php

namespace Drupal\entity_layout\Service;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use /** @noinspection PhpInternalEntityUsedInspection */
  Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Render\Element;

/**
 * Class LayoutRenderer
 * @package Drupal\entity_layout\Service
 */
class LayoutRenderer implements LayoutRendererInterface {

  /** @var array $themeRegistry */
  protected $themeRegistry;

  /** @var LayoutPluginManagerInterface $layoutPluginManager */
  protected $layoutPluginManager;

  /**
   * LayoutRenderer constructor.
   *
   * @param LayoutPluginManagerInterface $layout_plugin_manager
   */
  public function __construct(/** @noinspection PhpInternalEntityUsedInspection */ LayoutPluginManagerInterface $layout_plugin_manager) {
    $this->themeRegistry = theme_get_registry();
    $this->layoutPluginManager = $layout_plugin_manager;
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
        || false === array_key_exists($name, $build)
      ) {
        continue;
      }
      $build['#entity_layout']['weight'] = $build[$name]['#weight'];
      $build['#entity_layout']['display_unused'] = $build[$name]['#display_unused'];
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
      true === array_key_exists('render element', $theme_info)
      && true === array_key_exists($theme_info['render element'], $variables)
      && true === array_key_exists(
        '#entity_layout',
        $variables[$theme_info['render element']]
      )
      && true === array_key_exists('layouts', $variables[$theme_info['render element']]['#entity_layout'])
    ) {
      // Grab a reference to the original render array
      $build = &$variables[$theme_info['render element']];
      // Store layout info then cleanup the render array
      $layout_info = $build['#entity_layout'];
      $display_unused = $layout_info['display_unused'];
      unset($build['#entity_layout']);
      // The content part is the one actually renderd, so work on it
      $content = &$variables['content'];
      $this->performNesting($content, $layout_info);
      if (false === $display_unused) {
        foreach ($content as $name => &$element) {
          if ('_entity_layout' === $name) {
            continue;
          }
          $element['#access'] = false;
        }
      }
    }
  }

  /**
   * @param array $content
   * @param array $layout_info
   */
  protected function performNesting(array &$content, array $layout_info) {
    $content['_entity_layout'] = [
      '#weight' => $layout_info['weight'],
    ];
    /** @var array $layouts */
    $layouts = $layout_info['layouts'];
    foreach ($layouts as $delta => $layout) {
      $regions = $this->buildRegions($content, $layout);
      $renderable_layout = $this->layoutPluginManager
        ->createInstance($layout['#layout_id'])
        ->build($regions);
      $renderable_layout['#weight'] = $delta;
      $content['_entity_layout'][$delta] = $renderable_layout;
    }
    $this->fixDeltas($content['_entity_layout']);
  }

  /**
   * @param array $content
   * @param array $layout
   *
   * @return array
   */
  protected function buildRegions(array &$content, array $layout) {
    $regions = [];
    /** @var array $items */
    $items = $layout['#regions'];
    foreach ($items as $item_id => $item) {
      $region = $item['region'];
      if (false === array_key_exists($region, $regions)) {
        $regions[$region] = [];
      }
      $regions[$region][$item_id] = $this->extractRenderableItem(
        $item,
        $content
      );
    }

    return $regions;
  }

  /**
   * @param array $item
   * @param array $content
   *
   * @return array
   */
  protected function extractRenderableItem($item, array &$content) {
    if (
      false === array_key_exists('delta', $item)
      || false === array_key_exists('weight', $item)
      || false === array_key_exists('id', $item)
    ) {

      return [];
    }
    if ('' === $item['delta']) {
      /** @noinspection ReferenceMismatchInspection */
      if (true === array_key_exists($item['id'], $content)) {
        $renderable = $content[$item['id']];
        unset($content[$item['id']]);
        $renderable['#weight'] = $item['weight'];

        return $renderable;
      }
    } else {
      $root_id = str_replace(':' . $item['delta'], '', $item['id']);
      /** @noinspection ReferenceMismatchInspection */
      if (
        true === array_key_exists($root_id, $content)
        && true === array_key_exists($item['delta'], $content[$root_id])
      ) {
        $renderable = $content[$root_id][$item['delta']];
        unset($content[$root_id][$item['delta']]);
        $renderable['#weight'] = $item['weight'];

        return $renderable;
      }
    }

    return [];
  }

  /**
   * After elements have been dipatched over layouts, some field items deltas index may be broken.
   * Let's rewrite proper index starting with 0 to get multiple fields rendered properly.
   * 
   * @param array $layouts
   */
  protected function fixDeltas(array &$layouts) {
    foreach (Element::getVisibleChildren($layouts) as $layout_delta) {
      $layout = &$layouts[$layout_delta];
      foreach (Element::getVisibleChildren($layout) as $region_name) {
        $region = &$layout[$region_name];
        foreach ($region as $element_name => &$element) {
          if (
            false === array_key_exists('#is_multiple', $element)
            || false === $element['#is_multiple']
          ) {
            continue;
          }
          $items = [];
          foreach (Element::getVisibleChildren($element) as $delta) {
            $items[] = $element[$delta];
            unset($element[$delta]);
          }
          $element = array_merge($element, $items);
        }
      }
    }
  }
  
}
