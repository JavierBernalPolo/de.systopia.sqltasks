<?php
/*-------------------------------------------------------+
| SYSTOPIA SQL TASKS EXTENSION                           |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Sqltasks_ExtensionUtil as E;

/**
 * This action allows you to export campaign contacts
 *  if you have de.systopia.segmentation installed
 *
 * @see https://github.com/systopia/de.systopia.segmentation
 */
class CRM_Sqltasks_Action_SegmentationExport extends CRM_Sqltasks_Action {

  /**
   * Get identifier string
   */
  public function getID() {
    return 'segmentation_export';
  }

  /**
   * Get a human readable name
   */
  public function getName() {
    return E::ts('Segmentation Export');
  }

  /**
   * Build the configuration UI
   */
  public function buildForm(&$form) {
    parent::buildForm($form);

    $form->add(
      'select',
      $this->getID() . '_campaign_id',
      E::ts('Campaign'),
      $this->getEligibleCampaigns(),
      FALSE,
      array('class' => 'crm-select2 huge')
    );

    $form->add(
      'select',
      $this->getID() . '_segments',
      E::ts('Segments'),
      array(), // no segements to choose from initially
      FALSE,
      array('class' => 'crm-select2 huge', 'multiple' => 'multiple')
    );
    // store current value so we can set it when the segments are loaded
    $form->assign('segmentation_export_segments_current', json_encode($this->getSelectedSegments()));

    $form->add(
      'select',
      $this->getID() . '_exporter',
      E::ts('Exporter'),
      CRM_Segmentation_Exporter::getExporterList(),
      FALSE,
      array('class' => 'crm-select2 huge', 'multiple' => 'multiple')
    );

    // don't use date fields, so we can have tokens:

    // $form->addDate(
    //   $this->getID() . '_date_from',
    //   E::ts('Assignment not before'),
    //   FALSE,
    //   array('formatType' => 'activityDateTime'));

    // $form->addDate(
    //   $this->getID() . '_date_to',
    //   E::ts('Assignment not after'),
    //   FALSE,
    //   array('formatType' => 'activityDateTime'));

    $form->add(
      'text',
      $this->getID() . '_date_from',
      E::ts('Assignment not before'),
      array()
    );

    $form->add(
      'text',
      $this->getID() . '_date_to',
      E::ts('Assignment not after'),
      array()
    );

    $form->add(
      'checkbox',
      $this->getID() . '_date_current',
      E::ts('Current Assignment')
    );

    $form->add(
      'text',
      $this->getID() . '_filename',
      E::ts('File Name'),
      array('class' => 'huge')
    );

    $form->add(
      'text',
      $this->getID() . '_path',
      E::ts('File Path'),
      array('class' => 'huge')
    );

    $form->add(
      'text',
      $this->getID() . '_email',
      E::ts('Email to'),
      array('class' => 'huge')
    );

    $form->add(
      'select',
      $this->getID() . '_email_template',
      E::ts('Email Template'),
      $this->getAllTemplates(),
      FALSE,
      array('class' => 'crm-select2 huge')
    );

    $form->add(
      'text',
      $this->getID() . '_upload',
      E::ts('Upload to'),
      array('class' => 'huge')
    );
  }

  /**
   * get a list of eligible templates for the email
   */
  protected function getAllTemplates() {
    $template_options = array();
    $template_query = civicrm_api3('MessageTemplate', 'get', array(
      'is_active'    => 1,
      'return'       => 'id,msg_title',
      'option.limit' => 0));
    foreach ($template_query['values'] as $template) {
      $template_options[$template['id']] = $template['msg_title'];
    }
    return $template_options;
  }

  /**
   * Parse the credentials
   * @return FALSE if nothing is entered, 'ERROR' if it cannot be parsed
   */
  protected function getCredentials() {
    $credentials = $this->getConfigValue('upload');
    if (!empty($credentials)) {
      $credentials = trim($credentials);
      if (preg_match('#^sftp:\/\/(?<user>[^:]+):(?<password>[^@]+)@(?<host>[\w.-]+)(?<remote_path>\/[\/\w_-]+)$#', $credentials, $match)) {
        return $match;
      } else {
        return 'ERROR';
      }
    }
    return FALSE;
  }

  /**
   * get the preferred filename
   */
  protected function getFileName() {
    $file_name = $this->getConfigValue('filename');
    $file_name = trim($file_name);
    if (empty($file_name)) {
      $file_name = '{YmdHis}_export.csv';
    }

    // substitute tokens
    while (preg_match('/{(?P<token>[^}]+)}/', $file_name, $match)) {
      $token = $match['token'];
      $value = '';
      switch ($token) {
        case 'campaign_id':
          $value = $this->getConfigValue('campaign_id');
          break;

        case 'campaign_title':
          try {
            $campaign_id = $this->getConfigValue('campaign_id');
            if ($campaign_id) {
              $value = civicrm_api3('Campaign', 'getvalue', array(
                'return' => 'title',
                'id'     => $campaign_id));
            }
          } catch (Exception $e) {
            $value = "ERROR";
          }
          break;

        case 'campaign_external_identifier':
          try {
            $campaign_id = $this->getConfigValue('campaign_id');
            if ($campaign_id) {
              $value = civicrm_api3('Campaign', 'getvalue', array(
                'return' => 'external_identifier',
                'id'     => $campaign_id));
            }
          } catch (Exception $e) {
            // probably just not set....
          }
          break;

        default:
          # we'll assume it's a date token
          $value = date($token);
          break;
      }
      $file_name = str_replace('{' . $match['token'] . '}', $value, $file_name);
    }

    return $file_name;
  }


  /**
   * get the selected filepath
   */
  protected function getFilePath($filename = NULL) {
    $file_path = $this->getConfigValue('path');
    $file_path = trim($file_path);
    while (DIRECTORY_SEPARATOR == substr($file_path, strlen($file_path) - 1)) {
      $file_path = substr($file_path, 0, strlen($file_path) - 1);
    }

    // add the $filename if requested
    if ($filename) {
      $file_path .= DIRECTORY_SEPARATOR . $filename;
    }
    return trim($file_path);
  }

  /**
   * get the list of selected segment (ids)
   * as an array
   */
  protected function getSelectedSegments() {
    $segments = $this->getConfigValue('segments');
    if (empty($segments)) {
      return array();
    } elseif (is_array($segments)) {
      return $segments;
    } else {
      return explode(',', $segments);
    }
  }

  /**
   * Check if this action is configured correctly
   */
  public function checkConfiguration() {
    parent::checkConfiguration();

    // check campaign
    $campaign_id = $this->getConfigValue('campaign_id');
    if (!$campaign_id) {
      throw new Exception("No campaign selected", 1);
    }

    $exporters = $this->getConfigValue('exporter');
    if (empty($exporters)) {
      throw new Exception("No exporters selected", 1);
    }

    $use_last_assignment = $this->getConfigValue('date_current');
    if ($use_last_assignment) {
      $timestamp = CRM_Sqltasks_Action_SegmentationAssign::getAssignmentTimestamp($this->task->getID());
      if (!$timestamp) {
        throw new Exception("Restrict export to current assignemnt requested, but no current assignemnt detected. Activate the 'Assign to Campaign' task!", 1);
      }
    }

    // check file path
    $file_check = $this->getFilePath();
    if (!is_writeable($file_check)) {
      throw new Exception("Cannot export file to '{$file_check}'.", 1);
    }
  }


  /**
   * RUN this action
   */
  public function execute() {
    // get some basic data
    $this->resetHasExecuted();
    $campaign_id = $this->getConfigValue('campaign_id');
    $exported_files = array();

    // compile parameters
    $params = array();
    $segments = $this->getSelectedSegments();
    if (!empty($segments)) {
      $params['segments'] = $segments;
    }

    // Assignment timestamp
    $use_last_assignment = $this->getConfigValue('date_current');
    if ($use_last_assignment) {
      // take values from 'Assign to campaign'
      $timestamp = CRM_Sqltasks_Action_SegmentationAssign::getAssignmentTimestamp($this->task->getID());
      if ($timestamp) {
        $params['start_date'] = $timestamp;
        $params['end_date']   = $timestamp;
      }
    } else {
      $date_from = strtotime($this->getConfigValue('date_from'));
      if ($date_from) {
        $params['start_date'] = date('Y-m-d H:i:s', $date_from);
      }
      $date_to = strtotime($this->getConfigValue('date_to'));
      if ($date_to) {
        $params['end_date'] = date('Y-m-d H:i:s', $date_to);
      }
    }

    // FIRST: run all exporters
    $exporters = $this->getConfigValue('exporter');
    foreach ($exporters as $exporter_id) {
      // export file
      $exporter = CRM_Segmentation_Exporter::getExporter($exporter_id);
      $exporter->generateFile($campaign_id, $params);
      $exported_file = $exporter->getExportedFile();
      if ($exported_file) {
        $this->setHasExecuted(); // something was created
        $exported_file_name = $exporter->getFileName();
        $exported_files[$exported_file] = $exported_file_name;

        // add log entry
        $exporter_name = $exporter->getName();
        $this->log("Exporter '{$exporter_name}' to file '{$exported_file_name}'");

      } else {
        // add log entry
        $exporter_name = $exporter->getName();
        $this->log("Exporter '{$exporter_name}' did not produce a file.");
      }
    }

    if (!$this->hasExecuted()) {
      $this->log("No export produced, skipping upload/email.");
      return;
    }

    // NEXT: zip all files
    $filename = $this->getFileName();
    $filepath = $this->getFilePath($filename);
    if (file_exists($filepath)) {
      // make sure this is a fresh file, ZIP will append otherwise
      unlink($filepath);
      $this->log("Overwriting existing file '{$filepath}'.");
    }
    $zip = new ZipArchive();
    if ($zip->open($filepath, ZipArchive::CREATE)!==TRUE) {
      throw new Exception("Cannot open zipfile '{$filepath}'", 1);
    }
    foreach ($exported_files as $exported_file => $exported_file_name) {
      $zip->addFile($exported_file, $exported_file_name);
    }
    $zip->close();
    $this->log("Zipped file into '{$filepath}'");


    // PROCESS 1: EMAIL
    $config_email = $this->getConfigValue('email');
    $config_email_template = $this->getConfigValue('email_template');
    if (!empty($config_email) && !empty($config_email_template)) {
      // add all the variables
      $email_list = $this->getConfigValue('email');
      list($domainEmailName, $domainEmailAddress) = CRM_Core_BAO_Domain::getNameAndEmail();
      $emailDomain = CRM_Core_BAO_MailSettings::defaultDomain();
      $attachment  = array('fullPath'  => $filepath,
                           'mime_type' => 'application/zip',
                           'cleanName' => basename($filepath));
      // and send the template via email
      $email = array(
        'id'              => $this->getConfigValue('email_template'),
        // 'to_name'         => $this->getConfigValue('email'),
        'to_email'        => $this->getConfigValue('email'),
        'from'            => "SQL Tasks <{$domainEmailAddress}>",
        'reply_to'        => "do-not-reply@{$emailDomain}",
        'attachments'     => array($attachment),
        );
      civicrm_api3('MessageTemplate', 'send', $email);
      $this->log("Sent file to '{$email_list}'");
    }

    // PROCESS 2: UPLOAD
    if ($this->getConfigValue('upload')) {
      $credentials = $this->getCredentials();
      if ($credentials && $credentials != 'ERROR') {
        // connect
        require_once('Net/SFTP.php');
        define('NET_SFTP_LOGGING', NET_SFTP_LOG_SIMPLE);
        $sftp = new Net_SFTP($credentials['host']);
        if (!$sftp->login($credentials['user'], $credentials['password'])) {
          throw new Exception("Login to {$credentials['user']}@{$credentials['host']} Failed", 1);
        }

        // upload
        $target_file = $credentials['remote_path'] . '/' . $filename;
        if (!$sftp->put($target_file, $filepath, NET_SFTP_LOCAL_FILE)) {
          throw new Exception("Upload to {$credentials['user']}@{$credentials['host']} failed: " . $sftp->getSFTPLog(), 1);
        }

        $this->log("Uploaded file '{$filename}' to {$credentials['host']}/{$target_file}");

      } else {
        throw new Exception("Upload failed, couldn't parse credentials", 1);
      }
    }
  }
}