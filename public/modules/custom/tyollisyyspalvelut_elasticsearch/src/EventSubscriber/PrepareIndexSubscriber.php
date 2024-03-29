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
    $langcode = NULL;
    if (!empty($config) && isset($config['languages']['selected'])) {
      $langcode = $config['languages']['selected'][0];

      if (isset($standard_languages[$langcode])) {
        $stemmer_language = strtolower($standard_languages[$langcode][0]);
      }
    }

    $filter_name = $stemmer_language . '_stop';
    $filter_language = '_' . $stemmer_language . '_';

    if (isset($langcode) && $langcode === 'fi') {
      $indexConfig["body"]["settings"]["index"] = [
        "analysis" => [
          "analyzer" => [
            "index_analyzer" => [
              "tokenizer" => "standard",
              "filter" => [ "lowercase", "finnish_stop", "snowball_filter", "synonym_filter" ]
            ],
          ],
          "filter" => [
            "finnish_stop" => [
              "type" => "stop",
              "stopwords" => '_finnish_',
            ],
            "snowball_filter" => [
              "type" => "snowball",
              "language" => "Finnish"
            ],
            "synonym_filter" => [
              "type" => "synonym_graph",
              "synonyms" => $this->getSynonyms()
            ],
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

  private function getSynonyms(): array
  {
    return [
      "ajan varaus, ajanvaraus, ajanvarau",
      "aktivointi suunnitelma, aktivointisuunnitelma",
      "ammatin vaihtaja, ammatinvaihtajille",
      "ammatin valinta, ammatinvalinta",
      "ammatinvalinta psykologi, ammatinvalintapsykologi",
      "ammatti korkeakoulu, ammattikorkeakouluun",
      "anniskelu passi, anniskelupassi",
      "ansio luettelo, ansioluettelo, ansioluettelossa",
      "ansio päiväraha, ansiopäiväraha, ansiopäivä raha",
      "ansio sidonnainen, ansiosidonnainen",
      "aukiolo aika, aukioloaika",
      "digi taito, digitaito",
      "haku palvelu, hakupalvelu",
      "hygienia passi, hygieniapassi",
      "kevyt yrittäjä, kevytyrittäjä",
      "kieli koulutus, kielikoulutus",
      "kieli kurssi, kielikurssi",
      "korotus osa, korotusosa",
      "koti kunta, kotikunta",
      "kotoutumis aika, kotoutumisaika",
      "kotoutumis koulutus, kotoutumiskoulutus",
      "kotoutumis palvelu, kotoutumispalvelu",
      "kotoutumis suunnitelma, kotoutumissuunnitelma",
      "koulutuksen haku, koulutuksenhaku",
      "koulutus neuvonta, koulutusneuvonta",
      "kulu korvaus, kulukorvaus",
      "kunta kokeilu, kuntakokeilu",
      "maahan muutto, maahanmuutto",
      "minimi työaika, minimityöaika",
      "mobiili varmenne, mobiilivarmenne",
      "muutoksenhaku asiakirja, muutoksen haku asiakirja, muutoksenhakuasiakirja",
      "muutoksen haku, muutoksenhaku",
      "opinto polku, opintopolku",
      "opinto tuki, opintotuki",
      "opiskelu todistus, opiskelutodistus",
      "oppi sopimus, oppisopimus",
      "oppisopimus koulutus, oppisopimuskoulutus",
      "osaamis passi, osaamispassi",
      "osa työkykyinen, osatyö kykyinen, osatyökykyinen",
      "palaute lomake, palautelomake",
      "palkka tuki, palkkatuki",
      "palkka tukijakso, palkkatuki jakso, palkkatukijakso",
      "palkka tukikortti, palkkatuki kortti, palkkatukikortti",
      "perus päiväraha, peruspäivä raha, peruspäiväraha",
      "piilo työpaikka, piilotyö paikka, piilotyöpaikka",
      "poissaolo ilmoitus, poissaoloilmoitus",
      "psykologi palvelut, psykologipalvelut",
      "puhelin palvelut, puhelinpalvelut",
      "rekrytointi tapahtuma, rekrytointitapahtuma",
      "selvitys pyyntö, selvityspyyntö",
      "sosiaali ohjaaja, sosiaaliohjaaja",
      "sosiaali työntekijä, sosiaalityöntekijä",
      "startti raha, starttiraha",
      "terveyden tila, terveydentila",
      "terveys tarkastus, terveystarkastus",
      "toimeen tulo, toimeentulo",
      "toiminta kyky, toimintakyky",
      "toimi piste, toimipiste",
      "työ haastattelu, työhaastattelu",
      "työ hakemus, työhakemus",
      "työ hakemuspohja, työhakemus pohja, työhakemuspohja",
      "työ harjoittelu, työharjoittelu",
      "työ kokeilu, työkokeilu",
      "työ kokemus, työkokemus",
      "työ kykyselvitys, työkyky selvitys, työkykyselvitys",
      "työllistymis palvelu, työllistymispalvelu",
      "työllistymis suunnitelma, työllistymissuunnitelma",
      "työllisyys palvelu, työllisyyspalvelu",
      "työ markkina tori, työmarkkina tori, työmarkkinatori",
      "työmarkkina tuki, työ markkina tuki, työmarkkinatuki",
      "työn hakija, työnhakija",
      "työn haku, työnhaku",
      "työnhaku velvollisuus, työnhakuvelvollisuus",
      "työnhaku paja, työnhakupaja",
      "työn haku taidot, työn hakutaidot, työnhakutaidot",
      "työnhakuvalmennuksiin",
      "työnhaku valmennus, työn haku valmennus, työnhakuvalmennus",
      "työnhaku velvoite, työn haku velvoite, työnhakuvelvoite",
      "työpaikka sivusto, työ paikka sivusto, työpaikkasivusto",
      "työ sopimus, työsopimus",
      "työssä oloehto, työssä olo ehto, työssäoloehto",
      "työssäolo velvoitte, työssä olo velvoitte, työssäolovelvoitte",
      "työ suhde, työsuhde",
      "työ tarjous, työtarjous",
      "työ toiminta, työtoiminta",
      "työttömyys etuutta, työttömyysetuutta, työttömyysetuudella",
      "työttömyys kassa, työttömyyskassa",
      "työttömyys päiväraha, työttömyys päivä raha, työttömyyspäiväraha",
      "työttömyys turva, työttömyysturva, työttömyysturvaasi",
      "työvoima koulutus, työ voima koulutus, työvoimakoulutus",
      "ura valmennus, uravalmennus",
      "vastuu asiantuntija, vastuuasiantuntija",
      "velvoite työ, velvoitetyö",
      "velvoite työllistettävä, velvoitetyöllistettävä",
      "velvoite työ, velvoitetyö",
      "yhteydenotto pyyntö, yhteyden otto pyyntö, yhteydenottopyyntö",
      "haetut paikat ilmoitus,	työnhakuvelvollisuus",
      "harjoittelu,	työkokeilu",
      "kalenteri,	tapahtumat",
      "karenssi,	korvaukseton määräaika",
      "kassan raha	työttömyysturva",
      "Kelaraha,	työttömyysturva",
      "Kelaraha,	peruspäiväraha",
      "kotoutumiskoulu,	kotoutumiskoulutus",
      "kotoutumiskoulut,	kotoutumiskoulutus",
      "kotoutumis koulutus,	kotoutumiskoulutus",
      "koulu,	opiskelu",
      "koulutus,	opiskelu",
      "koulutus,	työvoimakoulutus",
      "kurssi,	opiskelu",
      "käsittelyajat,	työttömyysturvan käsittely",
      "lyhytkurssi,	opiskelu",
      "opiskelu,	omahtoiset opinnot työttömyysetuudella",
      "opiskelu,	omahtoiset opinnot",
      "palkkatukityö,	palkkatuki",
      "selvari,	selvityspyyntö",
      "avoimet työpaikat,	työnhaku",
      "avoimet paikat,	työnhaku",
      "työharjoittelu,	työkokeilu",
      "työkkäri,	te_palvelut",
      "työkkärin kurssi,	työvoimakoulutus",
      "työnhaku,	työnhakuvelvollisuus",
      "työttömyyspäiväraha,	ansioon suhteutettu päiväraha",
      "velvoite,	velvoitetyöllistäminen",
      "velvoite,	velvoitetyöllistettävä",
      "velvoite,	työnhakuvelvollisuus",
      "velvoite,	velvoitetyöpalvelu",
      "velvoite,	velvoitetyöpaikka",
    ];
  }

}
