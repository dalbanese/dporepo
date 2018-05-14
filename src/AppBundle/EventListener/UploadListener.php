<?php
namespace AppBundle\EventListener;

use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Oneup\UploaderBundle\Event\PostPersistEvent;
use AppBundle\Service\RepoValidateData;

class UploadListener
{
  /**
   * @var ObjectManager
   */
  // private $om;

  // public function __construct(ObjectManager $om)
  // {
  //   $this->om = $om;
  // }
  
  /**
   * @param object $event  UploaderBundle's event object
   *
   * See (even though the documentation is a bit outdated):
   * https://github.com/1up-lab/OneupUploaderBundle/blob/master/Resources/doc/custom_logic.md
   */
  public function onUpload(PostPersistEvent $event)
  {
    $validation_results = (object)[];

    // Event response, request, and file.
    $response = $event->getResponse();
    $request = $event->getRequest();
    $file = $event->getFile();

    // Posted data.
    $data = (object)[];
    $post = $request->request->all();
    $data->full_path = !empty($post['fullPath']) ? $post['fullPath'] : false;
    $data->job_id = !empty($post['jobId']) ? $post['jobId'] : false;
    $data->parent_record_id = !empty($post['parentRecordId']) ? $post['parentRecordId'] : false;
    $data->prevalidate = (!empty($post['prevalidate']) && ($post['prevalidate'] === 'true')) ? true : false;

    // Move uploaded files into the original directory structures, under a parent directory the jobId.
    if ($data->job_id && $data->parent_record_id) {
      $this->move_files($file, $data);
    }

    // Pre-validate
    if ($data->prevalidate && $data->job_id) {

      switch ($file->getExtension()) {
        case 'csv':
          // Run the CSV validation.
          $validation_results = $this->validate_metadata($data->job_id, $data->job_id_directory, $file->getBasename()); // , $this->container, $items
          break;
      }
      
      // If errors are generated, return via $response.
      if(count((array)$validation_results) && isset($validation_results->results->messages)) {
        // TODO: Remember to remove the already uploaded file?
        $response['error'] = json_encode($validation_results->results->messages);
        $response['csv'] = json_encode($validation_results->csv);
      }
    }

    return $response;
  }

  /**
   * @param object $data  Data object.
   * @param object $file  File object.
   */
  public function move_files($file = null, $data = null)
  {
    if (!empty($file) && !empty($data) && $data->job_id && $data->parent_record_id) {

      $data->job_id_directory = str_replace($file->getBasename(), '', $file->getPathname()) . $data->job_id;

      // Create a directory with the job ID as the name if not present.
      if (!file_exists($data->job_id_directory)) {
        mkdir($data->job_id_directory, 0755, true);
      }

      // If there's a full path, then build-out the directory structure.
      if ($data->full_path) {

        $data->new_directory_path = str_replace('/' . $file->getBasename(), '', $data->full_path);

        // Create a directory with the $data->new_directory_path as the name if not present.
        if (!file_exists($data->job_id_directory . '/' . $data->new_directory_path)) {
          mkdir($data->job_id_directory . '/' . $data->new_directory_path, 0755, true);
        }
        // Move the file into the directory
        if (!file_exists($data->job_id_directory . '/' . $data->new_directory_path . '/' . $file->getBasename())) {
          rename($file->getPathname(), $data->job_id_directory . '/' . $data->new_directory_path . '/' . $file->getBasename());
        } else {
          // Remove the uploaded file???
          if (is_file($file->getPathname())) {
            unlink($file->getPathname());
          }
        }
      }

      // If there isn't a full path, then move the files into the root of the jobId directory.
      if (!$data->full_path) {
        // Move the file into the directory
        if (!file_exists($data->job_id_directory . '/' . $file->getBasename())) {
          rename($file->getPathname(), $data->job_id_directory . '/' . $file->getBasename());
        } else {
          // Remove the uploaded file???
          if (is_file($file->getPathname())) {
            unlink($file->getPathname());
          }
        }
      }

    }
  }

  /**
   * @param int $job_id  The job ID
   * @param int $job_id_directory  The job directory
   * @return json
   */
  public function validate_metadata($job_id = null, $job_id_directory = null, $filename = null) // , $thisContainer, $itemsController
  {

    $blacklisted_fields = array();
    $data = (object)[];

    // TODO: feed this into this method.
    if(empty($job_id)) {
      $blacklisted_fields = array(
        'project_repository_id',
      );
    }

    $data->csv = $this->construct_import_data($job_id_directory, $filename); // , $thisContainer, $itemsController

    if(!empty($data->csv)) {
      // Set the schema to validate against.
      if(stristr($filename, 'projects')) {
        $schema = 'project';
      }
      if(stristr($filename, 'subjects')) {
        $schema = 'subject';
      }
      if(stristr($filename, 'items')) {
        $schema = 'item';
      }
      // Instantiate the RepoValidateData class.
      $repoValidate = new RepoValidateData();
      // Execute the validation.
      $data->results = (object)$repoValidate->validateData($data->csv, $schema, $blacklisted_fields);
    }

    return $data;
  }

  /**
   * @param string $job_id_directory  The upload directory
   * @param string $filename  The file name
   * @return array  Import result and/or any messages
   */
  public function construct_import_data($job_id_directory = null, $filename = null) // , $thisContainer, $itemsController
  {

    $json_object = array();

    if(!empty($job_id_directory)) {

      $finder = new Finder();
      $finder->files()->in($job_id_directory . '/');
      $finder->files()->name($filename);

      foreach ($finder as $file) {
        // Get the contents of the CSV.
        $csv = $file->getContents();
      }

      // Convert the CSV to JSON.
      $array = array_map('str_getcsv', explode("\n", $csv));
      $json = json_encode($array);

      // Convert the JSON to a PHP array.
      $json_array = json_decode($json, false);

      // Read the first key from the array, which is the column headers.
      $target_fields = $json_array[0];

      // TODO: move into a vz-specific method?
      // [VZ IMPORT ONLY] Convert field names to satisfy the validator.
      foreach ($target_fields as $tfk => $tfv) {
        // [VZ IMPORT ONLY] Convert the 'import_subject_id' field name to 'subject_repository_id'.
        if($tfv === 'import_subject_id') {
          $target_fields[$tfk] = 'subject_repository_id';
        }
      }

      // Remove the column headers from the array.
      array_shift($json_array);

      foreach ($json_array as $key => $value) {
        // Replace numeric keys with field names.
        foreach ($value as $k => $v) {
          $field_name = $target_fields[$k];
          unset($json_array[$key][$k]);
          // If present, bring the project_repository_id into the array.
          $json_array[$key][$field_name] = ($field_name === 'project_repository_id') ? (int)$id : null;
          // TODO: move into a vz-specific method?
          // [VZ IMPORT ONLY] Strip 'USNM ' from the 'subject_repository_id' field.
          $json_array[$key][$field_name] = ($field_name === 'subject_repository_id') ? (int)str_replace('USNM ', '', $v) : $v;

          // TODO: figure out a way to tap into the ItemsController.
          // Look-up the ID for the 'item_type' (not when validating data, only when importing data).
          // if ((debug_backtrace()[1]['function'] !== 'validate_metadata') && ($field_name === 'item_type')) {
          //   $item_type_lookup_options = $itemsController->get_item_types($thisContainer);
          //   $json_array[$key][$field_name] = (int)$item_type_lookup_options[$v];
          // }

        }
        // Convert the array to an object.
        $json_object[] = (object)$json_array[$key];
      }

    }

    // $this->dumper($json_object);

    return $json_object;
  }

  public function dumper($data = false, $die = true, $ip_address=false){
    if(!$ip_address || $ip_address == $_SERVER["REMOTE_ADDR"]){
      echo '<pre>';
      var_dump($data);
      echo '</pre>';
      if($die) die();
    }
  }

}