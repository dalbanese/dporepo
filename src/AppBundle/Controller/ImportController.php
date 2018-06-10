<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Driver\Connection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
// use Psr\Log\LoggerInterface;

use AppBundle\Form\UploadsParentPickerForm;
use AppBundle\Entity\UploadsParentPicker;

// Custom utility bundle
use AppBundle\Utils\AppUtilities;

class ImportController extends Controller
{
    /**
     * @var object $u
     */
    public $u;
    private $repo_storage_controller;
    private $tokenStorage;

    /**
     * Constructor
     * @param object  $u  Utility functions object
     */
    public function __construct(AppUtilities $u, RepoStorageHybridController $repo_storage_controller, TokenStorageInterface $tokenStorage) // , LoggerInterface $logger
    {
        // Usage: $this->u->dumper($variable);
        $this->u = $u;
        $this->repo_storage_controller = $repo_storage_controller;
        $this->tokenStorage = $tokenStorage;
        // $this->logger = $logger;
        // Usage:
        // $this->logger->info('Import started. Job ID: ' . $job_id);

        // TODO: move this to parameters.yml and bind in services.yml.
        $this->uploads_directory = __DIR__ . '/../../../web/uploads/repository/';
    }

    /**
     * @Route("/admin/import_csv/{job_id}/{parent_project_id}/{parent_record_id}", name="import_csv", defaults={"job_id" = null, "parent_project_id" = null, "parent_record_id" = null}, methods="GET")
     *
     * @param int $job_id Job ID
     * @param int $parent_project_id Project ID
     * @param int $parent_record_id Parent record ID
     * @param object $request Symfony's request object
     * @param object $validate ValidateMetadataController class
     */
    public function import_csv($job_id, $parent_project_id, $parent_record_id, Request $request, ValidateMetadataController $validate, ItemsController $itemsController, DatasetsController $datasetsController)
    {
      // Clear session data.
      $session = new Session();
      $session->remove('new_repository_ids');

      $job_log_ids = array();

      $this->repo_storage_controller->setContainer($this->container);

      // Set the job type (e.g. subjects metadata import, items metadata import, capture datasets metadata import).
      $job_data = $this->repo_storage_controller->execute('getRecord', array(
          'base_table' => 'job',
          'id_field' => 'job_id',
          'id_value' => $job_id,
          'omit_active_field' => true,
        )
      );
      // Throw a 404 if the job record doesn't exist.
      if(!$job_data) throw $this->createNotFoundException('The Job record doesn\'t exist');

      if(!empty($job_id) && !empty($parent_project_id) && !empty($parent_record_id)) {

        // Remove 'metadata import' from the $job_data['job_type'].
        $job_type = str_replace(' metadata import', '', $job_data['job_type']);

        if(!empty($job_type)) {
          // Prepare the data.
          $data = $this->prepare_data($job_type, $this->uploads_directory . $job_id, $itemsController, $datasetsController);
          // Ingest data.
          if(!empty($data)) {
            foreach($data as $csv_key => $csv_value) {
              $job_log_ids = $this->ingest_csv_data($csv_value, $job_id, $parent_project_id, $parent_record_id);
            }
          }
        }
      }

      // Update the job table to indicate that the CSV import failed.
      if(!empty($job_id) && empty($job_log_ids)) {
        $this->repo_storage_controller->execute('saveRecord', array(
          'base_table' => 'job',
          'record_id' => $job_id,
          'user_id' => $this->getUser()->getId(),
          'values' => array(
            'job_status' => 'failed',
            'date_completed' => date('Y-m-d H:i:s'),
            'qa_required' => 0,
            'qa_approved_time' => null,
          )
        ));
      }

      $this->addFlash('message', '<strong>Upload Succeeded!</strong> Files will be validated shortly. The validation scheduled task runs every 30 seconds, but it may take time to grind through the validation process. Please check back!');

      return $this->json($job_log_ids);
    }

    /**
     * @param string $job_type The job type (One of: subjects, items, capture datasets)
     * @param string $job_upload_directory The upload directory
     * @return array Import result and/or any messages
     */
    public function prepare_data($job_type = null, $job_upload_directory = null, $itemsController, $datasetsController)
    {

      $data = array();

      if(!empty($job_upload_directory)) {

        $finder = new Finder();
        $finder->files()->in($job_upload_directory);

        // Prevent additional CSVs from being imported according to the $job_type.
        // Assign keys to each CSV, with projects first, subjects second, and items third.
        foreach ($finder as $file) {
          if(($job_type === 'subjects') && stristr($file->getRealPath(), 'subjects')) {
            $csv[0]['type'] = 'subject';
            $csv[0]['data'] = $file->getContents();
          }
          if((($job_type === 'subjects') || ($job_type === 'items')) && stristr($file->getRealPath(), 'items')) {
            $csv[1]['type'] = 'item';
            $csv[1]['data'] = $file->getContents();
          }
          if((($job_type === 'subjects') || ($job_type === 'items') || ($job_type === 'capture datasets')) && stristr($file->getRealPath(), 'capture_datasets')) {
            $csv[2]['type'] = 'capture_dataset';
            $csv[2]['data'] = $file->getContents();
          }
        }

        // Sort the CSV array by key.

        ksort($csv);
        // Re-index the CSV array.
        $csv = array_values($csv);

        foreach ($csv as $csv_key => $csv_value) {

          // Convert the CSV to JSON.
          $array = array_map('str_getcsv', explode("\n", $csv_value['data']));
          $json = json_encode($array);

          // Convert the JSON to a PHP array.
          $json_array = json_decode($json, false);
          // Add the type to the array.
          $json_array['type'] = $csv_value['type'];

          // Read the first key from the array, which is the column headers.
          $target_fields = $json_array[0];

          // Remove the column headers from the array.
          array_shift($json_array);

          foreach ($json_array as $key => $value) {
            // Replace numeric keys with field names.
            if(is_numeric($key)) {
              foreach ($value as $k => $v) {

                $field_name = $target_fields[$k];

                unset($json_array[$key][$k]);

                // If present, bring the project_repository_id into the array.
                $json_array[$key][$field_name] = ($field_name === 'project_repository_id') ? (int)$id : null;

                // Set the value of the field name.
                $json_array[$key][$field_name] = $v;

                // Look-up the ID for the 'item_type'.
                if ($field_name === 'item_type') {
                  $item_type_lookup_options = $itemsController->get_item_types($this->container);
                  $json_array[$key][$field_name] = (int)$item_type_lookup_options[$v];
                }

                // Look-up the ID for the 'capture_method'.
                if ($field_name === 'capture_method') {
                  $capture_method_lookup_options = $datasetsController->get_capture_methods($this->container);
                  $json_array[$key][$field_name] = (int)$capture_method_lookup_options[$v];
                }

                // Look-up the ID for the 'capture_dataset_type'.
                if ($field_name === 'capture_dataset_type') {
                  $capture_dataset_type_lookup_options = $datasetsController->get_dataset_types($this->container);
                  $json_array[$key][$field_name] = (int)$capture_dataset_type_lookup_options[$v];
                }

                // Look-up the ID for the 'item_position_type'.
                if ($field_name === 'item_position_type') {
                  $item_position_type_lookup_options = $datasetsController->get_item_position_types($this->container);
                  $json_array[$key][$field_name] = (int)$item_position_type_lookup_options[$v];
                }

                // Look-up the ID for the 'focus_type'.
                if ($field_name === 'focus_type') {
                  $focus_type_lookup_options = $datasetsController->get_focus_types($this->container);
                  $json_array[$key][$field_name] = (int)$focus_type_lookup_options[$v];
                }

                // Look-up the ID for the 'light_source_type'.
                if ($field_name === 'light_source_type') {
                  $light_source_type_lookup_options = $datasetsController->get_light_source_types($this->container);
                  $json_array[$key][$field_name] = (int)$light_source_type_lookup_options[$v];
                }

                // Look-up the ID for the 'background_removal_method'.
                if ($field_name === 'background_removal_method') {
                  $background_removal_method_lookup_options = $datasetsController->get_background_removal_methods($this->container);
                  $json_array[$key][$field_name] = (int)$background_removal_method_lookup_options[$v];
                }

                // Look-up the ID for the 'cluster_type'.
                if ($field_name === 'cluster_type') {
                  $camera_cluster_types_lookup_options = $datasetsController->get_camera_cluster_types($this->container);
                  $json_array[$key][$field_name] = (int)$camera_cluster_types_lookup_options[$v];
                }

              }
              // Convert the array to an object.
              $data[$csv_key]['csv'][] = (object)$json_array[$key];
            }

            if(!is_numeric($key)) {
              $data[$csv_key]['type'] = $value;
            }
          }

        }

      }

      return $data;
    }

  /**
   * @param string $data  Data object
   * @param int $job_id  Job ID
   * @param int $parent_record_id  Parent record ID
   * @return array  An array of job log IDs
   */
  public function ingest_csv_data($data = null, $job_id = null, $parent_project_id = null, $parent_record_id = null) {

    $session = new Session();
    $data = (object)$data;
    $job_log_ids = array();
    $subject_repository_ids = NULL;
    $item_repository_ids = NULL;

    // User data.
    $user = $this->tokenStorage->getToken()->getUser();
    $data->user_id = $user->getId();
    // Job ID and parent record ID
    $data->job_id = !empty($job_id) ? $job_id : false;
    $data->parent_project_id = !empty($parent_project_id) ? $parent_project_id : false;
    $data->parent_record_id = !empty($parent_record_id) ? $parent_record_id : false;

    // Just in case: throw a 404 if either job ID or parent record ID aren't passed.
    if(!$data->job_id) throw $this->createNotFoundException('Job ID not provided.');
    if(!$data->parent_project_id) throw $this->createNotFoundException('Parent Project record ID not provided.');
    if(!$data->parent_record_id) throw $this->createNotFoundException('Parent record ID not provided.');

    // Check to see if the parent project record exists/active, and if it doesn't, throw a createNotFoundException (404).
    if(!empty($data->parent_project_id)) {
      $this->repo_storage_controller->setContainer($this->container);
      $project = $this->repo_storage_controller->execute('getProject', array('project_repository_id' => $data->parent_project_id));
      // If no project is returned, throw a createNotFoundException (404).
      if(!$project) throw $this->createNotFoundException('The Project record doesn\'t exist');
    }

    $this->repo_storage_controller->setContainer($this->container);

    // $data->type is referred to extensively throughout the logic.
    // $data->type can be one of: subject, item, capture_dataset

    // Insert into the job_log table
    $job_log_ids[] = $this->repo_storage_controller->execute('saveRecord', array(
      'base_table' => 'job_log',
      'user_id' => $data->user_id,
      'values' => array(
        'job_id' => $data->job_id,
        'job_log_status' => 'start',
        'job_log_label' => 'Import ' . $data->type,
        'job_log_description' => 'Import started',
      )
    ));

    // If data type is not a 'subject', set the array of $new_repository_ids.
    if ($data->type !== 'subject') {
      $new_repository_ids = $session->get('new_repository_ids');
    }

    foreach ($data->csv as $csv_key => $csv_val) {

      // Set the parent record's repository ID.
      switch ($data->type) {
        case 'subject':
          // Set the project_repository_id
          $csv_val->project_repository_id = (int)$data->parent_project_id;
          break;
        case 'item':
          // Set the subject_repository_id
          if (!empty($new_repository_ids) && !empty($csv_val->import_parent_id)) {
            $csv_val->subject_repository_id = $new_repository_ids[$csv_val->import_parent_id];
          } else {
            $csv_val->subject_repository_id = $data->parent_record_id;
          }
          break;
        case 'capture_dataset':
          // Set the parent_item_repository_id
          if (!empty($new_repository_ids) && !empty($csv_val->import_parent_id)) {
            $csv_val->parent_item_repository_id = $new_repository_ids[$csv_val->import_parent_id];
          } else {
            $csv_val->parent_item_repository_id = $data->parent_record_id;
          }
          break;
      }

      // Insert data from the CSV into the appropriate database table, using the $data->type as the table name.
      $this_id = $this->repo_storage_controller->execute('saveRecord', array(
        'base_table' => $data->type,
        'user_id' => $data->user_id,
        'values' => (array)$csv_val
      ));

      // Create an array of all of the newly created repository IDs.
      $new_repository_ids[$csv_val->import_row_id] = $this_id;

      // Set the description for the job log.
      switch ($data->type) {
        case 'subject':
          $data->description = $csv_val->local_subject_id . ' - ' . $csv_val->subject_display_name;
          break;
        case 'item':
          $data->description = $csv_val->item_display_name;
          break;
        case 'capture_dataset':
          $data->description = $csv_val->capture_dataset_name;
          break;
      }

      // Insert into the job_import_record table
      $job_import_record_id = $this->repo_storage_controller->execute('saveRecord', array(
        'base_table' => 'job_import_record',
        'user_id' => $data->user_id,
        'values' => array(
          'job_id' => $data->job_id,
          'record_id' => $this_id,
          'project_id' => (int)$data->parent_record_id,
          'record_table' => $data->type,
          'description' => $data->description,
        )
      ));

    }

    // Remove the session variable 'new_repository_ids'.
    $session->remove('new_repository_ids');

    // Set the session variable 'new_repository_ids'.
    $session->set('new_repository_ids', $new_repository_ids);

    // Insert into the job_log table
    // TODO: Feed the 'job_log_label' to the log leveraging fields from a form submission in the UI.
    $job_log_ids[] = $this->repo_storage_controller->execute('saveRecord', array(
      'base_table' => 'job_log',
      'user_id' => $data->user_id,
      'values' => array(
        'job_id' => $data->job_id,
        'job_log_status' => 'finish',
        'job_log_label' => 'Import ' . $data->type,
        'job_log_description' => 'Import finished',
      )
    ));

    // TODO: return something more than job log IDs?
    return $job_log_ids;
  }

    /**
     * @Route("/admin/import", name="import_summary_dashboard", methods="GET")
     *
     * @param object $conn Database connection object
     * @param object $request Symfony's request object
     */
    public function import_summary_dashboard(Connection $conn, Request $request)
    {
        $obj = new UploadsParentPicker();

        // Create the parent record picker typeahead form.
        $form = $this->createForm(UploadsParentPickerForm::class, $obj);

        return $this->render('import/import_summary_dashboard.html.twig', array(
            'page_title' => 'Uploads',
            'form' => $form->createView(),
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn)
        ));
    }

    /**
     * @Route("/admin/import/datatables_browse_imports", name="imports_browse_datatables", methods="POST")
     *
     * Browse Imports
     *
     * Run a query to retrieve all imports in the database.
     *
     * @param Request $request Symfony's request object
     * @return \Symfony\Component\HttpFoundation\JsonResponse The query result
     */
    public function datatables_browse_imports(Request $request)
    {
      $req = $request->request->all();
      $search = !empty($req['search']['value']) ? $req['search']['value'] : false;
      $sort_field = $req['columns'][ $req['order'][0]['column'] ]['data'];
      $sort_order = $req['order'][0]['dir'];
      $start_record = !empty($req['start']) ? $req['start'] : 0;
      $stop_record = !empty($req['length']) ? $req['length'] : 20;

      $query_params = array(
        'sort_field' => $sort_field,
        'sort_order' => $sort_order,
        'start_record' => $start_record,
        'stop_record' => $stop_record,
      );
      if ($search) {
        $query_params['search_value'] = $search;
      }

      $this->repo_storage_controller->setContainer($this->container);
      $data = $this->repo_storage_controller->execute('getDatatableImports', $query_params);

      return $this->json($data);
    }
    
    /**
     * @Route("/admin/import/{id}/{project_id}", name="import_summary_details", methods="GET")
     *
     * @param int $id Project ID
     * @param object $conn Database connection object
     * @param object $project ProjectsController class
     * @param object $request Symfony's request object
     */
    public function import_summary_details($id, $project_id, Connection $conn, ProjectsController $project, Request $request)
    {

      $project = [];
      $project['file_validation_errors'] = [];
      $this->repo_storage_controller->setContainer($this->container);

      if(!empty($id)) {
        // Check to see if the job exists. If it doesn't, throw a createNotFoundException (404).
        $job_data = $this->repo_storage_controller->execute('getRecord', array(
            'base_table' => 'job',
            'id_field' => 'job_id',
            'id_value' => $id,
            'omit_active_field' => true,
          )
        );
        if(empty($job_data)) throw $this->createNotFoundException('The Job record does not exist');
      }

      if(!empty($project_id)) {
        // Check to see if the parent record exists/active, and if it doesn't, throw a createNotFoundException (404).
        $project = $this->repo_storage_controller->execute('getProject', array('project_repository_id' => $project_id));
        if(!$project) throw $this->createNotFoundException('The Project record does not exist');
      }

      // Get the total number of Item records for the import.
      if(!empty($id)) {
        // Get project data.
        $items_total = $this->repo_storage_controller->execute('getImportedItems', array('job_id' => (int)$id));
        if($items_total) {
          // Merge items_total into $project.
          $project = array_merge($project, $items_total);
          // Get the uploaded files.
          $project['files'] = $this->get_directory_contents($id);
          // Get errors if they exist.
          $project['file_validation_errors'] = $this->repo_storage_controller->execute('getRecords', array(
              'base_table' => 'job_log',
              'fields' => array(),
              'search_params' => array(
                0 => array(
                  'field_names' => array(
                    'job_id'
                  ),
                  'search_values' => array(
                    (int)$id
                  ),
                  'comparison' => '='
                ),
                1 => array(
                  'field_names' => array(
                    'job_log_status'
                  ),
                  'search_values' => array(
                    'error'
                  ),
                  'comparison' => '='
                ),
                2 => array(
                  'field_names' => array(
                    'job_log_label'
                  ),
                  'search_values' => array(
                    'BagIt Validation'
                  ),
                  'comparison' => '='
                )
              ),
              'search_type' => 'AND',
              'sort_fields' => array(
                0 => array('field_name' => 'date_created')
              ),
              'omit_active_field' => true,
            )
          );
        }

      }

      return $this->render('import/import_summary_item.html.twig', array(
        'page_title' => $items_total ? $project['job_label'] : 'Uploads: ' . $project['project_name'],
        'project' => $project,
        'job_data' => $job_data,
        'id' => $id,
        'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn)
      ));
    }

    /**
     * @Route("/admin/import/{id}/datatables_browse_import_details", name="import_details_browse_datatables", methods="POST")
     *
     * Browse Import Details
     *
     * Run a query to retrieve the details of an import.
     *
     * @param  int $id The job ID
     * @param Request $request Symfony's request object
     * @return \Symfony\Component\HttpFoundation\JsonResponse The query result
     */
    public function datatables_browse_import_details($id, Request $request)
    {
      $req = $request->request->all();
      $search = !empty($req['search']['value']) ? $req['search']['value'] : false;
      $sort_field = $req['columns'][ $req['order'][0]['column'] ]['data'];
      $sort_order = $req['order'][0]['dir'];
      $start_record = !empty($req['start']) ? $req['start'] : 0;
      $stop_record = !empty($req['length']) ? $req['length'] : 20;

      $this->repo_storage_controller->setContainer($this->container);

      // Determine what was ingested (e.g. subjects, items, capture datasets).
      $job_data = $this->repo_storage_controller->execute('getRecord', array(
          'base_table' => 'job',
          'id_field' => 'job_id',
          'id_value' => $id,
          'omit_active_field' => true,
        )
      );

      // TODO: ^^^ error handling if job is not found? ^^^

      $query_params = array(
        'sort_field' => $sort_field,
        'sort_order' => $sort_order,
        'start_record' => $start_record,
        'stop_record' => $stop_record,
        'id' => $id,
        'job_type' => $job_data['job_type'],
      );

      if ($search) {
        $query_params['search_value'] = $search;
      }

      $data = $this->repo_storage_controller->execute('getDatatableImportDetails', $query_params);

      return $this->json($data);
    }

    /**
     * Create an array of all direcories and files found.
     *
     * @param int $job_id The Job ID.
     * @return array $data An array of all files found for a job.
     */
    private function get_directory_contents($job_id = null) {

      $data = [];

      if(!empty($job_id) && is_dir($this->uploads_directory . $job_id . '/')) {
        $finder = new Finder();
        $finder->files()->in($this->uploads_directory . $job_id . '/');

        foreach ($finder as $file) {
          $this_file = str_replace($this->uploads_directory . $job_id, '', $file->getPathname());
          // The following rigmarole is due to slash differences between Windows and Unix-based systems.
          $this_file = ltrim($this_file, DIRECTORY_SEPARATOR);
          // The simplified path to the file (minus absolute path structures).
          $data[] = str_replace('\\' . $file->getPathname(), '', $this_file);
        }
      }

      return $data;
    }

    /**
     * @Route("/admin/import/get_parent_records", name="get_parent_records", methods="POST")
     *
     * @param Request $request Symfony's request object
     * @return \Symfony\Component\HttpFoundation\JsonResponse The query result
     */
    public function get_parent_records(Request $request)
    {
      $data = $params = array();

      $req = $request->request->all();
      $params['query'] = !empty($req['query']) ? $req['query'] : false;
      $params['limit'] = !empty($req['limit']) ? $req['limit'] : false;
      $params['render'] = !empty($req['render']) ? $req['render'] : false;
      $params['property'] = !empty($req['property']) ? $req['property'] : false;

      $record_types = array(
        'project',
        'subject',
        'item',
      );

      foreach ($record_types as $key => $value) {

        $params['record_type'] = $value;

        switch($value) {
          case 'subject':
            $params['field_name'] = 'subject_display_name';
            $params['id_field_name'] = 'subject_repository_id';
            break;
          case 'item':
            $params['field_name'] = 'item_display_name';
            $params['id_field_name'] = 'item_repository_id';
            break;
          default: // project
            $params['field_name'] = 'project_name';
            $params['id_field_name'] = 'project_repository_id';
        }

        $this->repo_storage_controller->setContainer($this->container);

        // Query the database.
        $results = $this->repo_storage_controller->execute('getRecords', array(
          'base_table' => $params['record_type'],
          'fields' => array(),
          'limit' => (int)$params['limit'],
          'search_params' => array(
            // Lots of variables going on. Here's an example of what it looks like without variables:
            // 0 => array('field_names' => array('project.active'), 'search_values' => array(1), 'comparison' => '='),
            // 1 => array('field_names' => array('project.project_name'), 'search_values' => $params['query'], 'comparison' => 'LIKE')
            0 => array('field_names' => array($params['record_type'] . '.active'), 'search_values' => array(1), 'comparison' => '='),
            1 => array('field_names' => array($params['record_type'] . '.' . $params['field_name']), 'search_values' => $params['query'], 'comparison' => 'LIKE')
          ),
          'search_type' => 'AND',
          )
        );

        // Format the $data array for the typeahead-bundle.
        if(!empty($results)) {
          foreach ($results as $key => $value) {
            $data[] = array('id' => $value[ $params['id_field_name'] ], 'value' => $value[ $params['field_name'] ] . ' [ ' . strtoupper(str_replace('_', ' ', $params['record_type'])) . ' ]');
          }
        }
      }

      // Return data as JSON
      return $this->json($data);
    }

    
    /**
     * @param string $parent_record_type The record type (e.g. subject)
     * @return string
     */
    public function get_job_type($parent_record_type = null)
    {

      switch ($parent_record_type) {
        case 'project':
          $data = 'subjects';
          break;

        case 'subject':
          $data = 'items';
          break;

        case 'item':
          $data = 'capture datasets';
          break;
        
        default:
          $data = null;
          break;
      }

      return $data;
    }

    /**
     * @Route("/admin/create_job/{base_record_id}/{record_type}", name="create_job", defaults={"base_record_id" = null, "record_type" = null}, methods="GET")
     *
     * @param int $project_id The project ID
     * @param string $record_type The record type (e.g. subject)
     * @return JSON
     */
    public function create_job($base_record_id, $record_type, Request $request)
    {
      $job_id = null;
      $parent_records = [];
      $this->repo_storage_controller->setContainer($this->container);

      // Get the parent Project's record ID (unless it's a project to begin with).
      if(!empty($base_record_id) && !empty($record_type) && ($record_type !== 'project')) {
        $parent_records = $this->repo_storage_controller->execute('getParentRecords', array(
          'base_record_id' => $base_record_id,
          'record_type' => $record_type,
        ));
      } else {
        // If the $record_type is a 'project', just use the $base_record_id, since that's the project ID.
        $parent_records['project_repository_id'] = $base_record_id;
      }

      // If there are no results for a parent Project record ID, throw a createNotFoundException (404).
      if(empty($parent_records)) throw $this->createNotFoundException('Could not establish the parent project ID');

      if(!empty($parent_records) && isset($parent_records['project_repository_id'])) {
        // Check to see if the parent record exists/active, and if it doesn't, throw a createNotFoundException (404).
        $project = $this->repo_storage_controller->execute('getProject', array('project_repository_id' => $parent_records['project_repository_id']));
        if(!$project) throw $this->createNotFoundException('The Project record does not exist');
      }

      if(!empty($project)) {
        // Get the job type (what's being ingested?).
        $job_type = $this->get_job_type($record_type);
        // Insert a record into the job table.
        // TODO: Feed the 'job_label' and 'job_type' to the log leveraging fields from a form submission in the UI?
        $job_id = $this->repo_storage_controller->execute('saveRecord', array(
          'base_table' => 'job',
          'user_id' => $this->getUser()->getId(),
          'values' => array(
            'project_id' => (int)$project['project_repository_id'],
            'job_label' => 'Metadata Import: "' . $project['project_name'] . '"',
            'job_type' => $job_type . ' metadata import',
            'job_status' => 'in progress',
            'date_completed' => null,
            'qa_required' => 0,
            'qa_approved_time' => null,
          )
        ));
      }

      return $this->json(array('jobId' => (int)$job_id, 'projectId' => (int)$project['project_repository_id']));
    }
}
