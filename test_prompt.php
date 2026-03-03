<?php
namespace BoardingArea\SchemaAI\Admin {
  class Settings {
    public static function get_effective_api_key() { return 'foo'; }
    public const OPTION_MODEL = 'basai_model';
  }
}
namespace {
  define('ABSPATH', 1);
  function get_option($key, $def) { return 'gpt-4o'; }
  require 'includes/Api/class-openai-handler.php';
  require 'includes/Builder/class-schema-builder.php';
  $handler = new \BoardingArea\SchemaAI\Api\OpenAI_Handler();
  $reflector = new ReflectionClass($handler);
  $method = $reflector->getMethod('json_schema_definition');
  $method->setAccessible(true);
  $schema = $method->invoke($handler);
  // check length of anyOf array
  echo "Number of schemas: " . count($schema['properties']['result']['anyOf']) . "\n";
  // check total schema size
  echo "Schema size: " . strlen(json_encode($schema)) . "\n";
}
