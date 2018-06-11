<?php

namespace AppBundle\Service;

use Doctrine\DBAL\Driver\Connection;

use PDO;

use JsonSchema\{
    SchemaStorage,
    Validator,
    Constraints\Factory,
    Constraints\Constraint
};

class RepoValidateData implements RepoValidate {

  /**
   * @var string $schema_dir
   */
  public $schema_dir;

  /**
   * Constructor
   * @param object  $u  Utility functions object
   */
  public function __construct()
  {
    // TODO: move this to parameters.yml and bind in services.yml?
    $this->schema_dir = __DIR__ . '/../../../web/json/schemas/repository/';
  }

  /**
   * @param null $data The data to validate.
   * @param string $schema The schema to validate against (optional).
   * @param array $blacklisted_fields An array of fields to ignore (optional).
   * Validates incoming data against JSON Schema Draft 7. See:
   * http://json-schema.org/specification.html
   * JSON Schema for PHP Documentation: https://github.com/justinrainbow/json-schema
   * @return mixed array containing success/fail value, and any messages.
   */
  public function validateData($data = NULL, $schema = 'project', $blacklisted_fields = array()) {

    $schema_definitions_dir = ($schema !== 'project') ? 'definitions/' : '';

    $return = array('is_valid' => false);

    // If no data is passed, set a message.
    if(empty($data)) $return['messages'][] = 'Nothing to validate. Please provide an object to validate.';

    // If data is passed, go ahead and process.
    if(!empty($data)) {

      $integer_field_types = $boolean_field_types = array();

      $jsonSchemaObject = json_decode(file_get_contents($this->schema_dir . $schema_definitions_dir . $schema . '.json'));

      // TEMPORARY: Keep conditional and var_dump() here for debugging during development.
      // Once everything is humming along, go ahead and remove this.
      // if($schema === 'capture_dataset') {

        // Convert the CSV's integer-based fields to integers... 
        // Convert the CSV's boolean-based fields to booleans... 
        // because out of the box, all of the CSV's array values are strings.

        // First, reference the JSON schema to gather:
        // 1) all of the field names with the type of integer.
        // 2) all of the field names with the type of boolean.
        $schema_properties = $jsonSchemaObject->items->properties;
        foreach ($schema_properties as $key => $value) {
          // Integer field types
          if(isset($value->type) && ($value->type === 'integer')) {
            $integer_field_types[] = $key;
          }
          // Boolean field types
          if(isset($value->type) && ($value->type === 'boolean')) {
            $boolean_field_types[] = $key;
          }
        }

        // Convert field values from string to integer or boolean.
        foreach ($data as $data_key => $data_value) {
          foreach ($data_value as $dv_key => $dv_value) {
            // Cast to integer
            if(!empty($integer_field_types) && in_array($dv_key, $integer_field_types)) {
              $data[$data_key]->$dv_key = (int)$dv_value;
            }
            // Cast to boolean
            if(!empty($boolean_field_types) && in_array($dv_key, $boolean_field_types)) {
              $data[$data_key]->$dv_key = (bool)$dv_value;
            }
          }
        }

        // echo '<pre>';
        // var_dump($data);
        // echo '</pre>';
        // die();
      // }

      $schemaStorage = new SchemaStorage();
      $schemaStorage->addSchema('file://' . $this->schema_dir . $schema_definitions_dir, $jsonSchemaObject);

      $jsonValidator = new Validator( new Factory($schemaStorage) );
      $jsonValidator->validate($data, $jsonSchemaObject, Constraint::CHECK_MODE_APPLY_DEFAULTS);

      if ($jsonValidator->isValid()) {
        $return['is_valid'] = true;
      } else {

        $return['is_valid'] = false;

        foreach ($jsonValidator->getErrors() as $error) {
          // Ignore blacklisted field.
          // TODO: Loop through blacklisted fields (right now, only using the first one, $blacklisted_fields[0]).
          // if(!strstr($error['property'], $blacklisted_fields[0])) {
            $row = str_replace('[', 'Row ', $error['property']);
            $row = str_replace(']', '', $row);
            $row = str_replace('.', ' - Field: ', $row);
            $return['messages'][] = array('row' => $row, 'error' => $error['message']);
          // }
        }

        // If there are no messages, then return true for 'is_valid'.
        if(!isset($return['messages'])) {
          $return['is_valid'] = true;
        }

      }

    }

    return $return;
  }

}