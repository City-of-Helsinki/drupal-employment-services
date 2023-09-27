<?php

/**
 * @file
 * Contains site specific overrides.
 */

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'CHANGE-ME-IN-ENVIRONMENT-SETTINGS';

if (getenv('ELASTIC_URL')) {
  $config['elasticsearch_connector.cluster.tyollisyyspalvelut']['url'] = getenv('ELASTIC_URL');

  if (getenv('ELASTIC_USER') && getenv('ELASTIC_PASSWORD')) {
    $config['elasticsearch_connector.cluster.tyollisyyspalvelut']['options']['use_authentication'] = '1';
    $config['elasticsearch_connector.cluster.tyollisyyspalvelut']['options']['authentication_type'] = 'Basic';
    $config['elasticsearch_connector.cluster.tyollisyyspalvelut']['options']['username'] = getenv('ELASTIC_USER');
    $config['elasticsearch_connector.cluster.tyollisyyspalvelut']['options']['password'] = getenv('ELASTIC_PASSWORD');
  }
}

$settings['http_client_config']['timeout'] = 240;
ini_set('default_socket_timeout', 240);
