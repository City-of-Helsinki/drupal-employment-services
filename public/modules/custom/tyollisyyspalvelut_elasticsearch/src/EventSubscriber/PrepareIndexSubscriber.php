<?php

namespace Drupal\tyollisyyspalvelut_elasticsearch\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\elasticsearch_connector\ElasticSearch\Parameters\Factory\IndexFactory;
use Drupal\elasticsearch_connector\Event\PrepareIndexEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\search_api\IndexInterface;

/**
 * Class EntityTypeSubscriber.
 *
 * @package Drupal\tyollisyyspalvelut_elasticsearch\EventSubscriber
 */
class PrepareIndexSubscriber implements EventSubscriberInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\Entity
   */
  private $entityTypeManager;

  /**
   * Constructs a new DefaultSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   *   The event names to listen for, and the methods that should be executed.
   */
  public static function getSubscribedEvents() {
    return [
        PrepareIndexEvent::PREPARE_INDEX => 'prepareIndex',
    ];
  }

  /**
   * Called on elasticsearch_connector.prepare_index event.
   */
  public function prepareIndex(PrepareIndexEvent $event) {
    $indexConfig = $event->getIndexConfig();
    $index = $this->loadIndexFromIndexName($event->getIndexName());

    $stemmer_language = 'english';
    $config = $this->getDatasourceConfig($index);
    $standard_languages = LanguageManager::getStandardLanguageList();
    if (!empty($config) && isset($config['languages']['selected'])) {
      $langcode = $config['languages']['selected'][0];
      //var_dump('$config--->'. $config);

      if (isset($standard_languages[$langcode])) {
        $stemmer_language = strtolower($standard_languages[$langcode][0]);
      }
    }

    $filter_name = $stemmer_language . '_stop';
    $filter_language = '_' . $stemmer_language . '_';



    if (($stemmer_language === 'english' ||  $stemmer_language === 'swedish')
      && !str_contains($event->getIndexName(), 'units_fi')) {
      $indexConfig["body"]["settings"]["index"] = [
        "analysis" => [
          "analyzer" => [
            "default" => [
              "type" => "custom",
              "filter" => [
                "lowercase",
                "stop",
                "filter_stemmer",
                $filter_name,
              ],
              "tokenizer" => "standard",
            ],
          ],
          "filter" => [
            "filter_stemmer" => [
              "type" => "stemmer",
              "language" => $stemmer_language,
            ],
            $filter_name => [
              "type" => "stop",
              "stopwords" => $filter_language,
            ],
          ],
        ],
      ];
    }
    else {
      $indexConfig["body"]["settings"]["index"] = [
        "analysis" => [
          "analyzer" => [
            "index_analyzer" => [
              "tokenizer" => "standard",
              "filter" => [ "lowercase", "synonym_filter", "finnish_stop", "raudikkoFilter"  ]
            ],
          ],
          "filter" => [
            "synonym_filter" => [
              "type" => "synonym_graph",
              "synonyms" => [
                "PS, PlayStation, Play Station",
                "universe, cosmos",
                "te-palvelut, oma asiointi",
                "i-pod, ipod"
              ]
            ],
            "finnish_stop" => [
              "type" => "stop",
              "stopwords" => '_finnish_',
            ],
            "my_snow" => [
              "type" => "snowball",
              "language" => "Finnish"
            ],
          ],
        ],
      ];
    }


    $event->setIndexConfig($indexConfig);
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
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');

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
