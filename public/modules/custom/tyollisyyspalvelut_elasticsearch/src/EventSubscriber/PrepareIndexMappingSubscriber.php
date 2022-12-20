<?php

namespace Drupal\tyollisyyspalvelut_elasticsearch\EventSubscriber;

use Drupal\elasticsearch_connector\Event\PrepareIndexMappingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class EntityTypeSubscriber.
 *
 * @package Drupal\tyollisyyspalvelut_elasticsearch\EventSubscriber
 */
class PrepareIndexMappingSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   *
   * @return array
   *   The event names to listen for, and the methods that should be executed.
   */
  public static function getSubscribedEvents() {
    return [
      PrepareIndexMappingEvent::PREPARE_INDEX_MAPPING => 'prepareIndexMapping',
    ];
  }

  /**
   * Event to customize field properties and analyzers.
   *
   * @param \Drupal\elasticsearch_connector\Event\PrepareIndexMappingEvent $event
   *   Event.
   */
  public function prepareIndexMapping(PrepareIndexMappingEvent $event) {
    $mappingParams = $event->getIndexMappingParams();

    if (isset($mappingParams['type'])) {
      $indexKey = $mappingParams['type'];
      foreach ($mappingParams['body'][$indexKey]['properties'] as $key => $property) {
        if ($property['type'] == 'text') {
          $mappingParams['body'][$indexKey]['properties'][$key]['analyzer'] = $mappingParams['body'][$indexKey]['properties'][$key]['analyzer'] ?? 'standard';
        }
      }
    }
    else {
      foreach ($mappingParams['body']['properties'] as $key => $property) {
        if ($property['type'] == 'text') {
          $mappingParams['body']['properties'][$key]['analyzer'] = $mappingParams['body']['properties'][$key]['analyzer'] ?? 'standard';
          if ($key == 'title') {
            $mappingParams['body']['properties'][$key]['analyzer'] = 'default';
            unset($mappingParams['body']['properties'][$key]['search_analyzer']);
          }
        }
      }
    }
    $event->setIndexMappingParams($mappingParams);
  }

}