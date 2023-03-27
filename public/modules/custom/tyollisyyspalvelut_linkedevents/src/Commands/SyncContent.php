<?php

namespace Drupal\tyollisyyspalvelut_linkedevents\Commands;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Drupal\Core\Utility\Error;

/**
 * SyncContent drush commands.
 */
class SyncContent extends DrushCommands {
  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * The Taxonomy term storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $termStorage;

  // @codingStandardsIgnoreStart
  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  /** @phpstan-ignore-next-line */
  private EntityTypeManagerInterface $entityTypeManager;
  // @codingStandardsIgnoreEnd

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private ClientInterface $httpClient;

  /**
   * Content type.
   *
   * @var string
   */
  private string $contentType;

  /**
   * Used ID to use as content author.
   *
   * @var int
   */
  private int $userId;

  /**
   * Data URL for fetching the content.
   *
   * @var string
   */
  private string $dataUrl;

  /**
   * Configured site languages.
   *
   * @var \Drupal\Core\Language\LanguageInterface[]
   */
  private array $languages;

  /**
   * Allowed tags for the content.
   *
   * @var array|string[]
   */
  private array $allowedTags;

  /**
   * Tracks changes.
   *
   * @var array|int[]
   */
  private array $processLog;

  /**
   * How many items to fetch per request.
   *
   * @var int
   */
  private int $dataChunkSize;

  /**
   * Event logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * SyncContentDrushCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $factory
   *   The logger.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ClientInterface $http_client, LoggerChannelFactoryInterface $factory) {
    parent::__construct();
    $this->dataChunkSize = 50;
    $this->dataUrl = 'https://api.hel.fi/linkedevents/v1/event/?include=location&publisher=ahjo:u02120030,ahjo:u021200&keyword=yso:p6357&sort=-end_time&page_size='.$this->dataChunkSize;
    $this->contentType = 'event';
    $this->userId = 2; // LinkedEvent user
    $this->allowedTags = ["maahanmuuttajat", "nuoret", "info", "koulutus", "messut", "neuvonta", "rekrytointi", "työpajat", "digitaidot", "etätapahtuma", "palkkatuki", "työnhaku"];
    $this->processLog = ['new' => 0, 'updated' => 0, 'deleted' => 0];
    $this->languages = \Drupal::languageManager()->getLanguages();
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $http_client;
    $this->termStorage = $entityTypeManager->getStorage('taxonomy_term'); // @TODO Can be used for translated tags.
    $this->nodeStorage = $entityTypeManager->getStorage('node');
    $this->loggerFactory = $factory;
  }

  /**
   * Sync Linked Events data to Drupal.
   *
   * @param int $limit
   *   Number of items to import.
   *
   * @command tyollisyyspalvelut_linkedevents:sync
   * @usage tyollisyyspalvelut_linkedevents:sync
   * @aliases linkedevents:sync
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function sync(int $limit = 0): void {
    $this->loggerFactory->get('tyollisyyspalvelut_linkedevents')->info('Events sync started.');

    // Fetch first chunk of the data
    $data = $this->fetch($this->dataUrl);

    // Output info
    $this->output()->writeln('Updating nodes..');

    // Loop while we have fetched data
    while ($data) {
      // For debugging data can be limited
      if ($limit > 0) {
        $data->data = array_slice($data->data, 0, $limit);
        $data->meta->next = null;
      }

      // Process fetched data
      $this->nodeUpdate($data->data);
      // Read next chunk of data, or get NULL to stop the loop
      $data = $this->fetch($data->meta->next);
    }

    // Output info
    $this->output()->writeln('Removing expired..');

    // Remove expired nodes
    $this->removeExpiredNodes();

    // Form message for the event
    $message = 'Events sync completed. '
      .'Added ('. $this->processLog['new'] .'). '
      .'Updated ('. $this->processLog['updated'] .'). '
      .'Deleted ('. $this->processLog['deleted'] .'). '
      .'Took '.  \Drupal::time()->getCurrentTime() - \Drupal::time()->getRequestTime() .'s.';

    // Log event.
    $this->loggerFactory->get('tyollisyyspalvelut_linkedevents')
      ->info($message);

    // Output to console.
    $this->output()->writeln($message);
  }

  /**
   * Fetch Linked Events data.
   *
   * @param string|null $url
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function fetch(string|null $url): mixed {
    if (!$url) {
      return NULL;
    }

    // Run http query.
    try {
      $response = $this->httpClient->request('GET', $url);
    }
    catch (ClientException $exception) {
      $variables = Error::decodeException($exception);
      $this->logger->error('%type: @message in %function (line %line of %file).', $variables);
      return NULL;
    }

    // Return data as json.
    return json_decode($response->getBody()->getContents());
  }

  /**
   * Update data on the database.
   *
   * @param array $data
   *   Fetched data to process.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function nodeUpdate(array $data): void {
    foreach ($data as $item) {
      // Skip expired items
      if (strtotime($item->end_time) < (\Drupal::time()->getRequestTime())) {
        continue;
      };

      // Init new or existing node object with finnish translation as default.
      $node = $this->nodeInit($item);

      // Current item hasn't changed since last save
      if ($node->get('field_last_modified_time')->value === $item->last_modified_time) {
        continue;
      };

      // Create original node with default fields and default language.
      $node = $this->nodeAddDefaults($node, $item);
      $node = $this->nodeAddTranslation($node, $item, 'fi');
      // Update process log.
      $node->isNew() ? $this->processLog['new']++ : $this->processLog['updated']++;
      // Save the node.
      $node->save();

      /** @var \Drupal\node\Entity\Node $node */
      foreach ($this->languages as $langcode => $language) {
        if ($langcode === 'fi') {
          continue;
        }

        if ($node->HasTranslation($langcode)) {
          $translation = $node->getTranslation($langcode);
          $this->processLog['updated']++;
        }
        else {
          $translation = $node->addTranslation($langcode);
          $this->processLog['new']++;
        }

        $translation = $this->nodeAddTranslation($translation, $item, $langcode);
        $translation->save();
      }
    }
  }

  /**
   * Populate default fields with values.
   *
   * @param $node
   * @param $source
   *
   * @return \Drupal\Core\Entity\EntityInterface
   */
  private function nodeAddDefaults($node, $source): EntityInterface {
    $node->field_image_name = count($source->images) > 0 ? $source->images[0]->name : '';
    $node->field_image_url = count($source->images) > 0 ? $source->images[0]->url : '';
    $node->field_image_alt = count($source->images) > 0 ? $source->images[0]->alt_text : '';

    $node->field_location_id = (int) preg_replace('/[^0-9]/', '', $source->location->id);
    $node->field_publisher = $source->publisher;
    $node->field_start_time = strtotime($source->start_time) * 1000;
    $node->field_end_time = strtotime($source->end_time) * 1000;
    $node->set('field_last_modified_time', $source->last_modified_time);
    $node->field_event_status = $source->event_status;
    return $node;
  }

  /**
   * Populate translatable fields with values.
   *
   * @param $node
   * @param $source
   * @param $langcode
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function nodeAddTranslation($node, $source, $langcode) {
    /** @var \Drupal\node\Entity\Node $node */
    $node->setTitle($source->name->$langcode ?? $source->name->fi);
    $node->field_location = $source->location->name->$langcode ?? $source->location->name->fi ?? '';
    $node->field_short_description = $source->short_description->$langcode ?? $source->short_description->fi ?? '';
    $node->field_text = [
      'value' => $source->description->$langcode ?? $source->description->fi ?? '',
      'format' => 'basic_html',
    ];
    $node->field_info_url = isset($source->info_url->$langcode) && strlen($source->info_url->$langcode) <= 255 ? $source->info_url->$langcode : '';
    $node->field_location_extra_info = $source->location_extra_info->$langcode ?? $source->location_extra_info->fi ?? '';
    $node->field_street_address = $source->location->street_address->$langcode ?? $source->location->street_address->fi ?? '';

    // Hardcode tags to finnish for now.
    $node->field_tags = $this->getTags($source->keywords, 'fi');

    foreach ($source->offers as $offer) {
      // Check the URL is not empty or too long.
      if (empty($offer->info_url->$langcode) || strlen($offer->info_url->$langcode) > 255) {
        continue;
      }
      $node->field_offers_info_url = $offer->info_url->$langcode;
    }

    if ($source->location->name->fi === 'Internet') {
      $tags = [];
      foreach ($node->get('field_tags') as $field) {
        $tags[] = $field->value;
      }
      $tags[] = 'etätapahtuma';
      $node->set('field_tags', $tags);
    }

    return $node;
  }

  /**
   * Node object initializer.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Returns node object.
   */
  private function nodeInit(\stdClass $source): EntityInterface {
    // Build query to fetch existing nodes.
    $query = $this->nodeStorage->getQuery();
    $query->condition('type', $this->contentType);
    $query->condition('field_id', $source->id);
    $query->condition('langcode', 'fi');
    $exists = $query->execute();


    if ($exists) {
      // Use existing node
      return $this->nodeStorage->load(current($exists));
    }

    // Create a new node object.
    return $this->nodeStorage->create(
      [
        'type' => $this->contentType,
        'uid' => $this->userId,
        'title' => $source->name->fi,
        'langcode' => 'fi',
        'field_id' => $source->id,
      ]
    );
  }

  /**
   * Remove expired nodes by event's end time.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function removeExpiredNodes(): void {
    $ids = $this->nodeStorage->getQuery()
      ->condition('status', 1)
      ->condition('type', $this->contentType)
      ->condition('field_end_time', \Drupal::time()->getRequestTime(), '<')
      ->execute();

    foreach ($ids as $id) {
      $node = $this->nodeStorage->load($id);
      $node->delete();
      $this->processLog['deleted']++;
    }
  }

  /**
   * Get tags for the event.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function getTags($keywords, $langcode): array {
    $tags = [];

    foreach ($keywords as $keyword) {
      $data = $this->fetch($keyword->{'@id'});
      $tags[] = $data->name->fi; // @TODO tags are not translated in Drupal.
    }

    return str_replace('maahanmuuttajat', 'maahan_muuttaneet', array_filter($tags,
      function ($tag) {
        return in_array($tag, $this->allowedTags);
      }
    ));
  }
}
