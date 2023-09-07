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
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use stdClass;

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
   * Term vocabulary keywords.
   *
   * @var string
   */
  private string $termVocabulary;

  /**
   * Term vocabulary languages.
   *
   * @var string
   */
  private string $termLanguageVocabulary;

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
  private array $allLanguages;

  /**
   * Enabled languages.
   *
   * @var string[]
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
    $this->termVocabulary = 'event_tags';
    $this->termLanguageVocabulary = 'event_languages';
    $this->userId = 2; // LinkedEvent user
    $this->allowedTags = [
      "maahanmuuttajat",
      "nuoret",
      "info",
      "koulutus",
      "messut",
      "neuvonta",
      "rekrytointi",
      "työpajat",
      "digitaidot",
      "etätapahtuma",
      "palkkatuki",
      "työnhaku"
    ];
    $this->processLog = ['new' => 0, 'updated' => 0, 'deleted' => 0, 'unpublished' => 0];
    $this->languages = ['fi', 'sv', 'en'];
    $this->allLanguages = \Drupal::languageManager()->getLanguages();
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
   * @param string $method
   *   If set to "update", force updates all nodes.
   *
   * @command tyollisyyspalvelut_linkedevents:sync
   * @usage tyollisyyspalvelut_linkedevents:sync
   * @aliases linkedevents:sync
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function sync(int $limit = 0, string $method = ''): void {
    $this->loggerFactory->get('tyollisyyspalvelut_linkedevents')->info('Events sync started.');

    if ($method === 'update') {
      $update_all = TRUE;
    }
    else {
      $update_all = FALSE;
    }

    $this->output()->writeln('Checking deleted events..');
    $this->checkDeletedEvents();

    $data = $this->fetch($this->dataUrl);
    $this->output()->writeln('Updating nodes..');
    while ($data) {
      // For debugging data can be limited
      if ($limit > 0) {
        $data->data = array_slice($data->data, 0, $limit);
        $data->meta->next = null;
      }

      // Process fetched data
      $this->nodeUpdate($data->data, $update_all);
      // Read next chunk of data, or get NULL to stop the loop
      $data = $this->fetch($data->meta->next);
    }
    
    $this->output()->writeln('Removing expired..');
    $this->removeExpiredNodes();

    // Form message for the event
    $message = 'Events sync completed. '
      .'Added ('. $this->processLog['new'] .'). '
      .'Updated ('. $this->processLog['updated'] .'). '
      .'Deleted ('. $this->processLog['deleted'] .'). '
      .'Unpublished ('. $this->processLog['unpublished'] .'). '
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
      $this->logger->error($exception->getMessage());
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
   * @param bool $update_all
   *   Force update all nodes.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function nodeUpdate(array $data, bool $update_all): void {
    foreach ($data as $item) {
      // Skip collection items
      if (!empty($item->sub_events)) {
        continue;
      }

      // Skip expired items
      if (strtotime($item->end_time) < (\Drupal::time()->getRequestTime())) {
        continue;
      };

      // Init new or existing node object with finnish translation as default.
      $node = $this->nodeInit($item);
      if (!$node instanceof NodeInterface) {
        continue;
      }

      // Current item hasn't changed since last save
      if (!$update_all && $node->get('field_last_modified_time')->value === $item->last_modified_time) {
        continue;
      };

      // Create original node with default fields and default language.
      $node = $this->nodeAddDefaults($node, $item);
      $node = $this->nodeAddTranslation($node, $item, 'fi');

      // Add keywords as taxonomy terms, along with translations.
      $node = $this->nodeAddTaxonomyTerms($node, $item);

      $node = $this->nodeAddLanguageTaxonomyTerms($node, $item);

      // Update process log.
      $node->isNew() ? $this->processLog['new']++ : $this->processLog['updated']++;
      // Save the node.
      $node->save();

      foreach ($this->languages as $langcode) {
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
   * @return \Drupal\node\NodeInterface
   */
  private function nodeAddDefaults(NodeInterface $node, $source): NodeInterface {
    $node->field_image_name = count($source->images) > 0 ? $source->images[0]->name : '';
    $node->field_image_url = count($source->images) > 0 ? $source->images[0]->url : '';
    $node->field_image_alt = count($source->images) > 0 ? $source->images[0]->alt_text : '';

    $node->field_location_id = (int) preg_replace('/[^0-9]/', '', $source->location->id);
    $node->field_start_time = strtotime($source->start_time) * 1000;
    $node->field_end_time = strtotime($source->end_time) * 1000;
    $node->set('field_last_modified_time', $source->last_modified_time);
    $node->field_event_status = $source->event_status;

    $data = $this->fetch("https://api.hel.fi/linkedevents/v1/organization/" . $source->publisher  );
    $node->field_publisher = $data->name;

    return $node;
  }

  /**
   * Add keywords and their translations to node as taxonomy terms.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node to edit.
   * @param \stdClass $source
   *   Entity source from API.
   *
   * @return \Drupal\node\NodeInterface
   *   Node with taxonomy terms added.
   */
  private function nodeAddTaxonomyTerms(NodeInterface $node, \stdClass $source): NodeInterface {
    $tids = [];
    foreach ($source->keywords as $keyword) {
      $data = $this->fetch($keyword->{'@id'});
      if (!in_array($data->name->fi, $this->allowedTags)) {
        continue;
      }

      $term = $this->termInit($data, $this->termVocabulary, 'field_id');
      $term->save();

      foreach ($this->languages as $langcode) {
        if ($langcode === 'fi') {
          continue;
        }
        $this->addTermTranslation($term, $data, $langcode);
      }

      $tids[] = $term->id();
    }

    if ($source->location->name->fi === 'Internet') {
      $source->location->name->fi = 'etätapahtuma';
      $source->location->name->en = 'remote event';
      $source->location->name->sv = 'distansevenemang';

      $internet_term = $this->termInit($source->location,  $this->termVocabulary, 'field_id');
      $internet_term->save();
      foreach ($this->languages as $langcode) {
        if ($langcode === 'fi') {
          continue;
        }
        $this->addTermTranslation($internet_term, $source->location, $langcode);
      }
      $tids[] = $internet_term->id();
    }

    $node->set('field_event_tags', $tids);
    return $node;
  }

  /**
   * Add languages and their translations to node as taxonomy terms.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node to edit.
   * @param \stdClass $source
   *   Entity source from API.
   *
   * @return \Drupal\node\NodeInterface
   *   Node with taxonomy terms added.
   */
  private function nodeAddLanguageTaxonomyTerms(NodeInterface $node, \stdClass $source): NodeInterface {
    $tids = [];
    foreach ($source->in_language as $lang) {
      $data = $this->fetch($lang->{'@id'});
      $term = $this->termInit($data, $this->termLanguageVocabulary, 'field_language_id');
      $term->save();
      $tids[] = $term->id();
    }

    $node->set('field_in_language', $tids);
    return $node;
  }

  /**
   * Add translation for node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node to add translation for.
   * @param \stdClass $source
   *   Entity data from API.
   * @param string $langcode
   *   Language code for translation.
   *
   * @return \Drupal\node\NodeInterface
   *   Translated version of node.
   */
  private function nodeAddTranslation(NodeInterface $node, \stdClass $source, string $langcode): NodeInterface {
    /** @var \Drupal\node\Entity\Node $node */
    $default_translation = $node->getTranslation('fi');
    $node->setTitle($source->name->$langcode ?? $default_translation->title->value);

    // If short description isn't set, try to get value from other languages.
    $short_description = NULL;
    if (!empty($source->short_description->$langcode)) {
      $short_description = $source->short_description->$langcode;
    }
    else {
      foreach ($this->languages as $fallback_lang) {
        if (!empty($source->short_description->$fallback_lang)) {
          $short_description = $source->short_description->$fallback_lang;
          break;
        }
      }
    }

    // If description isn't set, try to get value from other languages.
    $description = NULL;
    if (!empty($source->description->$langcode)) {
      $description = $source->description->$langcode;
    }
    else {
      foreach ($this->languages as $fallback_lang) {
        if (!empty($source->description->$fallback_lang)) {
          $description = $source->description->$fallback_lang;
          break;
        }
      }
    }

    $node->field_short_description = $short_description;
    $node->field_text = [
      'value' => $description,
      'format' => 'basic_html',
    ];
    $node->field_location = $source->location->name->$langcode ?? $source->location->name->fi ?? '';
    $node->field_info_url = isset($source->info_url->$langcode) && strlen($source->info_url->$langcode) <= 255 ? $source->info_url->$langcode : '';
    $node->field_location_extra_info = $source->location_extra_info->$langcode ?? $source->location_extra_info->fi ?? '';
    $node->field_street_address = $source->location->street_address->$langcode ?? $source->location->street_address->fi ?? '';
    $node->field_provider = $source->provider->$langcode ?? $source->provider->fi ?? '';

    // Hardcode tags to finnish for now.
    $node->field_tags = $this->getTags($source->keywords, 'fi');

    foreach ($source->offers as $offer) {
      // Check the URL is not empty or too long.
      if (empty($offer->info_url->$langcode) || strlen($offer->info_url->$langcode) > 255) {
        continue;
      }
      $node->field_offers_info_url = $offer->info_url->$langcode;
    }

    // @todo Legacy version of tag implementation.
    // this should be removed when new version is approved.
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
   * @param \stdClass $source
   *   Entity data from API.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Returns node object or NULL if one can't be created.
   */
  private function nodeInit(\stdClass $source): ?NodeInterface {
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


    // Get default title from fi, sv or en.
    if (!empty($source->name->fi)) {
      $title = $source->name->fi;
    }
    elseif (!empty($source->name->sv))
    {
      $title = $source->name->sv;
    }
    elseif (!empty($source->name->en))
    {
      $title = $source->name->en;
    }
    else {
      return NULL;
    }


    // Create a new node object.
    return $this->nodeStorage->create(
      [
        'type' => $this->contentType,
        'uid' => $this->userId,
        'title' => $title,
        'langcode' => 'fi',
        'field_id' => $source->id,
      ]
    );
  }

  /**
   * Taxonomy term object initializer.
   *
   * @param \stdClass $source
   *   Source entity from API.
   *
   * @param string $taxonomyTerm
   *   Taxonomy term name.
   *
   * @param string $fieldID
   *   Taxonomy term ID field.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   Returns taxonomy term object.
   */
  private function termInit(\stdClass $source, string $taxonomyTerm, string $fieldID): TermInterface {
    // Build query to fetch existing nodes.
    $query = $this->termStorage->getQuery();
    $query->condition('vid', $taxonomyTerm);
    $query->condition($fieldID, $source->id);
    $query->condition('langcode', 'fi');
    $exists = $query->execute();

      if ($taxonomyTerm === $this->termVocabulary && $source->name->fi === 'maahanmuuttajat') {
        $name = 'maahan muuttaneet';
      }
      else {
        $name = $source->name->fi;
      }

    if ($exists) {
      // Use existing term
      $term = $this->termStorage->load(current($exists));
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $term->set('name', $name);
      return $term;
    }

    // Create a new term object.
    return $this->termStorage->create(
      [
        'vid' => $taxonomyTerm,
        'uid' => $this->userId,
        'name' => $name,
        'langcode' => 'fi',
        $fieldID => $source->id,
      ]
    );
  }

  /**
   * Add translation to term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   Term to add translation to.
   * @param \stdClass $source
   *   Source entity from API.
   * @param string $langcode
   *   Langcode to add translation to.
   *
   * @return \Drupal\taxonomy\TermInterface
   */
  private function addTermTranslation(TermInterface $term, \stdClass $source, string $langcode): TermInterface {
    if (isset($source->name->$langcode)) {
      $name = $source->name->$langcode;
    }
    else {
      $name = $source->name->fi;
    }

    if ($term->hasTranslation($langcode)) {
      $translated_term = $term->getTranslation($langcode);
    }
    else {
      $translated_term = $term->addTranslation($langcode);
    }

    $translated_term->set('name', $name);
    $translated_term->save();
    return $translated_term;
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
   * Check non expired nodes to see if they are removed from the API.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function checkDeletedEvents(): void {
    $ids = $this->nodeStorage->getQuery()
      ->condition('status', 1)
      ->condition('type', $this->contentType)
      ->condition('field_end_time', \Drupal::time()->getRequestTime(), '>=')
      ->execute();

    foreach ($ids as $id) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->nodeStorage->load($id);
      if (!$node->hasField('field_id') || $node->get('field_id')->isEmpty()) {
        continue;
      }

      $url = 'https://api.hel.fi/linkedevents/v1/event/' . $node->get('field_id')->value;
      if ($this->fetch($url) === NULL) {
        $node->set('status', 0);
        $node->save();
        $this->processLog['unpublished']++;
      }
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
