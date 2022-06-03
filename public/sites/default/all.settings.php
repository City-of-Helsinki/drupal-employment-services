<?php

/**
 * @file
 * Contains site specific overrides.
 */

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'CHANGE-ME-IN-ENVIRONMENT-SETTINGS';

if (getenv('ELASTIC_URL')) {
  $config['elasticsearch_connector.cluster.infofinland']['url'] = getenv('ELASTIC_URL');

  if (getenv('ELASTIC_USER') && getenv('ELASTIC_PASSWORD')) {
    $config['elasticsearch_connector.cluster.infofinland']['options']['use_authentication'] = '1';
    $config['elasticsearch_connector.cluster.infofinland']['options']['authentication_type'] = 'Basic';
    $config['elasticsearch_connector.cluster.infofinland']['options']['username'] = getenv('ELASTIC_USER');
    $config['elasticsearch_connector.cluster.infofinland']['options']['password'] = getenv('ELASTIC_PASSWORD');
  }
}
