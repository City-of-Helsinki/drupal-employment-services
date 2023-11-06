<?php

namespace Drupal\tyollisyyspalvelut_elasticsearch\EventSubscriber;

use Drupal\elasticsearch_connector\ElasticSearch\Parameters\Factory\IndexFactory;
use Drupal\elasticsearch_connector\Event\PrepareIndexMappingEvent;
use Drupal\search_api\IndexInterface;
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
    $index = $this->loadIndexFromIndexName($event->getIndexName());
    $config = $this->getDatasourceConfig($index);
    $mappingParams = $event->getIndexMappingParams();

    if (!empty($config) && isset($config['languages']['selected'])) {
      $languages = $config['languages']['selected'];
      foreach ($languages  as $lang) {
        if (isset($mappingParams['type'])) {
          $indexKey = $mappingParams['type'];
          foreach ($mappingParams['body'][$indexKey]['properties'] as $key => $property) {
            if ($property['type'] == 'text') {
              $mappingParams['body'][$indexKey]['properties'][$key]['analyzer'] = $mappingParams['body'][$indexKey]['properties'][$key]['analyzer'] ?? $lang .'_analyzer';
            }
          }
        }
        else {
          foreach ($mappingParams['body']['properties'] as $key => $property) {
            if ($property['type'] == 'text') {
              $mappingParams['body']['properties'][$key]['analyzer'] = $mappingParams['body']['properties'][$key]['analyzer'] ?? $lang .'_analyzer';
              if ($key == 'title') {
                $mappingParams['body']['properties'][$key]['analyzer'] = $lang.'_analyzer';
                unset($mappingParams['body']['properties'][$key]['search_analyzer']);
              }
            }
          }
        }
      }
    }
    else {
      if (isset($mappingParams['type'])) {
        $indexKey = $mappingParams['type'];
        foreach ($mappingParams['body'][$indexKey]['properties'] as $key => $property) {
          if ($property['type'] == 'text') {
            $mappingParams['body'][$indexKey]['properties'][$key]['analyzer'] = $mappingParams['body'][$indexKey]['properties'][$key]['analyzer'] ?? 'index_analyzer';
          }
        }
      } else {
        foreach ($mappingParams['body']['properties'] as $key => $property) {
          if ($property['type'] == 'text') {
            $mappingParams['body']['properties'][$key]['analyzer'] = $mappingParams['body']['properties'][$key]['analyzer'] ?? 'index_analyzer';
            if ($key == 'title') {
              $mappingParams['body']['properties'][$key]['analyzer'] = 'index_analyzer';
              unset($mappingParams['body']['properties'][$key]['search_analyzer']);
            }
          }
        }
      }
    }
    $event->setIndexMappingParams($mappingParams);
  }

  /**
   * Loads the index entity associated with this event.
   *
   * @param string $index_name
   *   The long index name as a string.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The loaded index or NULL.
   */
  private function loadIndexFromIndexName($index_name) {

    $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');

    /** @var \Drupal\search_api\Entity\Index[] $search_api_indexes */
    $search_api_indexes = $index_storage->loadMultiple();

    foreach ($search_api_indexes as $search_api_index) {
      $elasticsearch_connector_index_name = IndexFactory::getIndexName($search_api_index);
      if ($index_name == $elasticsearch_connector_index_name) {
        return $search_api_index;
      }
    }

    return NULL;
  }

  /**
   * Returns the datasource configuration for the given index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index entity.
   *
   * @return array
   *   An array representing the datasource configuration.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function getDatasourceConfig(IndexInterface $index) {
    $config = [];
    if ($index->isValidDatasource('entity:node')) {
      $config = $index->getDatasource('entity:node')->getConfiguration();
    }
    return $config;
  }

}
