<?php

/**
 * This is a CRON script which should be called from the command-line, not the
 * web. For example something like:
 * /usr/bin/php /path/to/site/cli/autoupdate.php
 */

// Set flag that this is a parent file.
  const _JEXEC = 1;

// Initialize Environment
  error_reporting(E_ALL | E_NOTICE);
  ini_set('display_errors', 1);
  set_time_limit(0);

// Define ase
  if( !defined('JPATH_BASE') ){
    define('JPATH_BASE', dirname(getcwd()));
  }

// Load system defines
  if( file_exists(JPATH_BASE . '/defines.php') ){
    require_once JPATH_BASE . '/defines.php';
  }

// Load defaut defines
  if( !defined('_JDEFINES') ){
    require_once JPATH_BASE . '/includes/defines.php';
  }

// Load application
  require_once JPATH_LIBRARIES . '/import.legacy.php';
  require_once JPATH_LIBRARIES . '/cms.php';

// Load the configuration
  require_once JPATH_CONFIGURATION . '/configuration.php';

// Required Libraries
  jimport('joomla.updater.update');

/**
 * This script will download and install all available updates.
 *
 * @since  3.4
 */
  class AutoUpdateCron extends JApplicationCli {

    public function __construct(JInputCli $input = null, JRegistry $config = null, JDispatcher $dispatcher = null){

      // CLI Constructor
        parent::__construct($input, $config, $dispatcher);

      // Utilities
        $this->db        = JFactory::getDBO();
        $this->updater   = JUpdater::getInstance();
        $this->installer = JComponentHelper::getComponent('com_installer');

      // Validate Log Path
        $logPath = $this->config->get('log_path');
        if( !is_dir($logPath) || !is_writeable($logPath) ){
          $logPath = JPATH_BASE . '/logs';
          if( !is_dir($logPath) || !is_writeable($logPath) ){
            $this->out('Log Path not found - ' . $logPath);
          }
          $this->config->set('log_path', JPATH_BASE . '/logs');
        }

      // Validate Tmp Path
        $tmpPath = $this->config->get('tmp_path');
        if( !is_writeable($tmpPath) ){
          $tmpPath = JPATH_BASE . '/tmp';
          if( !is_dir($tmpPath) || !is_writeable($tmpPath) ){
            $this->out('Tmp Path not found - ' . $tmpPath);
          }
          $this->config->set('tmp_path', JPATH_BASE . '/tmp');
        }

    }

    public function doFindUpdates(){

      // Get the update cache time
        $cache_timeout = $this->installer->params->get('cachetimeout', 6, 'int');
        $cache_timeout = 3600 * $cache_timeout;

      // Find all updates
        $this->out('Fetching updates...');
        $this->updater->findUpdates(0, $cache_timeout);
        $this->out('Finished fetching updates');

    }

    public function getNextUpdateId(){

      $query = $this->db->getQuery(true)
        ->select('update_id')
        ->from('#__updates')
        ->where($this->db->quoteName('extension_id') . ' != ' . $this->db->quote(0));

      /**
       * TODO
       * Implement flags for limiting / expanding basic operation
       */
      /*
      if( !$this->input->get('core') ){
        ->where($this->db->quoteName('extension_id') . ' != ' . $this->db->quote(700));
      }
      */

      return
        $this->db
          ->setQuery($query, 0, 1)
          ->loadResult();

    }

    public function doInstallUpdate( $update_id ){

      // Load
        $this->out('Loading update #'. $update_id .'...');
        $update = new JUpdate();
        $updateRow = JTable::getInstance('update');
        $updateRow->load( $update_id );
        $update->loadFromXml($updateRow->detailsurl, $this->installer->params->get('minimum_stability', JUpdater::STABILITY_STABLE, 'int'));
        $update->set('extra_query', $updateRow->extra_query);

      // Download
        $tmpPath = $this->config->get('tmp_path');
        if( !is_writeable($tmpPath) ){
          $tmpPath = JPATH_BASE . '/tmp';
        }
        $url = $update->downloadurl->_data;
        if ($extra_query = $update->get('extra_query')){
          $url .= (strpos($url, '?') === false) ? '?' : '&amp;';
          $url .= $extra_query;
        }
        $this->out(' - Download ' . $url);
        $p_file = JInstallerHelper::downloadPackage($url);
        if( $p_file ){
          $filePath = $tmpPath . '/' . $p_file;
        }
        else {
          $this->out(' - Download Failed, Attempting alternate download method...');
          $urlFile = preg_replace('/^.*\/(.*?)$/', '$1', $url);
          $filePath = $tmpPath . '/' . $urlFile;
          if( $fileHandle = @fopen($filePath, 'w+') ){
            $curl = curl_init($url);
            curl_setopt_array($curl, [
              CURLOPT_URL            => $url,
              CURLOPT_FOLLOWLOCATION => 1,
              CURLOPT_BINARYTRANSFER => 1,
              CURLOPT_RETURNTRANSFER => 1,
              CURLOPT_FILE           => $fileHandle,
              CURLOPT_TIMEOUT        => 50,
              CURLOPT_USERAGENT      => 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)'
            ]);
            $response = curl_exec($curl);
            if( $response === false ){
              $this->out(' - Download Failed: ' . curl_error($curl));
              return false;
            }
          }
          else {
            $this->out(' - Download Failed, Error writing ' . $filePath);
            return false;
          }
        }

      // Catch Error
        if( !is_file($filePath) ){
          $this->out(' - Download Failed/ File not found');
          return false;
        }

      // Extracting Package
        $this->out(' - Extracting Package...');
        $package = JInstallerHelper::unpack($filePath);
        if( !$package ){
          $this->out(' - Extract Failed');
          JInstallerHelper::cleanupInstall($filePath);
          return false;
        }

      // Install the package
        $this->out(' - Installing ' . $package['dir'] . '...');
        $installer = JInstaller::getInstance();
        $update->set('type', $package['type']);
        if( !$installer->update($package['dir']) ){
          $this->out(' - Update Error');
          $result = false;
        }
        else {
          $this->out(' - Update Success');
          $result = true;
        }

      // Cleanup the install files
        $this->out(' - Cleanup');
        JInstallerHelper::cleanupInstall($package['packagefile'], $package['extractdir']);

      // Complete
        return $result;

    }

    public function doIterateUpdates(){

      $this->out('Processing Updates...');
      while( $update_id = $this->getNextUpdateId() ){
        if( !$this->doInstallUpdate( $update_id ) ){
          $this->out(' - Installation Failed - ABORT');
          return false;
        }
      }
      $this->out('Update processing complete');

    }

    public function doExecute(){

      $this->doFindUpdates();
      $this->doIterateUpdates();

    }

  }

// Trigger Execution
  JApplicationCli::getInstance('AutoUpdateCron')
    ->execute();
