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

  $payload = [
     'type' => 'BlogPosting',
     'justification' => 'text',
     'summary' => 'text',
     'missing_info' => [],
     'details' => []
  ];
  $result = ['result' => $payload];

  $keys = ['type', 'justification', 'summary', 'details'];
  foreach ($keys as $k) {
     if (!array_key_exists($k, $payload)) {
        echo "Missing: $k\n";
     }
  }
}
