<?php

/**
 * v0.0.2
 *
 * This is a CLI Script only
 * /usr/bin/php /path/to/site/cli/autoupdate.php
 *
 *  Operations
 *  -f, --fetch             Run Fetch
 *  -u, --update            Run Update
 *  -l, --list              List Updates
 *  -x, --export            Export Updates JSON
 *
 *  Update Filters
 *  -i, --id ID             Update ID
 *  -a, --all               All Packages
 *  -V, --version VER       Version Filter
 *  -c, --core              Joomla! Core Packages
 *  -e, --extension LOOKUP  Extension by ID/NAME
 *  -t, --type VAL          Type
 *
 *  Additional Flags
 *  -v, --verbose           Verbose
 *
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

      // Push to Global Config
        $config = JFactory::getConfig();
        $config->set('tmp_path', $this->config->get('tmp_path'));
        $config->set('log_path', $this->config->get('log_path'));

    }

    public function doFetchUpdates(){

      // Get the update cache time
        $cache_timeout = $this->installer->params->get('cachetimeout', 6, 'int');
        $cache_timeout = 3600 * $cache_timeout;

      // Find all updates
        $this->out('Fetching updates...');
        $this->updater->findUpdates(0, $cache_timeout);
        $this->out('Finished fetching updates');

    }

    public function getNextUpdateId( $lookup = null ){

      $update_row = $this->getNextUpdateRow( $lookup );
      return $update_row ? $update_row->id : null;

    }

    public function getNextUpdateRow( $lookup = null ){

      $query = $this->db->getQuery(true)
        ->select('*')
        ->from('#__updates')
        ->where($this->db->quoteName('extension_id') . ' != ' . $this->db->quote(0));

      $lookup = array();
      if( is_numeric($lookup) ){
        $lookup = array('extension_id' => $lookup);
      }
      else if( is_string($lookup) ){
        $lookup = array('element' => $lookup);
      }
      else if( is_array($lookup) ){
        $lookup = (array)$lookup;
      }

      foreach( $lookup AS $key => $val ){
        $query->where($this->db->quoteName( $key ) . ' = ' . $this->db->quote($val));
      }

      return
        $this->db
          ->setQuery($query, 0, 1)
          ->loadObject();

    }

    public function doInstallUpdate( $update_row ){

      // Load
        $this->out('Loading update #'. $update_row->update_id .'...');
        $update = new JUpdate();
        $updateRow = JTable::getInstance('update');
        $updateRow->load( $update_row->update_id );
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

      // Build Update Filter
        $update_lookup = array();

        if( $this->input->get('a', $this->input->get('all')) ){
        }

        if( $this->input->get('c', $this->input->get('core')) ){
          $update_lookup[] = array(
            'type'    => 'file',
            'element' => 'joomla',
            'version' => $this->input->get('v', $this->input->get('version'))
            );
        }

        if( $extension_lookup = $this->input->get('e', $this->input->get('extension')) ){
          if( is_string($extension_lookup) ){
            $update_lookup[] = array(
              'element' => $extension_lookup,
              'type'    => $this->input->get('t', $this->input->get('type')),
              'version' => $this->input->get('V', $this->input->get('version'))
              );
          }
          else if( is_numeric($extension_lookup) ){
            $update_lookup[] = array(
              'extension_id' => $extension_lookup,
              'type'         => $this->input->get('t', $this->input->get('type')),
              'version'      => $this->input->get('V', $this->input->get('version'))
              );
          }
        }

      // List / Export / Process Updates
        $update_id  = $this->input->get('i', $this->input->get('id'));
        $update_row = null;
        if( $update_id ){
          $update_row = (object)array('update_id' => $update_id);
        }
        else {
          $update_row = $this->getNextUpdateRow( array_shift($update_lookup) );
        }
        if( $update_row ){
          $do_list   = $this->input->get('l', $this->input->get('list'));
          $do_export = !$do_list && $this->input->get('x', $this->input->get('export'));
          $do_update = !$do_list && !$do_export && $this->input->get('u', $this->input->get('update'));
          if( $do_list ){
            $this->out(implode('',array(
              str_pad('update_id', 10, ' ', STR_PAD_RIGHT),
              str_pad('extension_id', 15, ' ', STR_PAD_RIGHT),
              str_pad('element', 30, ' ', STR_PAD_RIGHT),
              str_pad('type', 15, ' ', STR_PAD_RIGHT),
              str_pad('version', 10, ' ', STR_PAD_RIGHT)
              )));
          }
          do {
            if( $do_export ){
              $this->out( json_encode($update_row) );
            }
            if( $do_list ){
              $this->out(implode('',array(
                str_pad($update_row->update_id, 10, ' ', STR_PAD_RIGHT),
                str_pad($update_row->extension_id, 15, ' ', STR_PAD_RIGHT),
                str_pad($update_row->element, 30, ' ', STR_PAD_RIGHT),
                str_pad($update_row->type, 15, ' ', STR_PAD_RIGHT),
                str_pad($update_row->version, 10, ' ', STR_PAD_RIGHT)
                )));
            }
            if( $do_update ){
              $this->out('Processing Update #' . $update_row->update_id .'...');
              if( !$this->doInstallUpdate( $update_row ) ){
                $this->out(' - Installation Failed - ABORT');
                return false;
              }
            }
          } while(
            count($update_lookup)
            && $update_row = $this->getNextUpdateRow(array_shift($update_lookup))
            );
          if( $do_update ){
            $this->out('Update processing complete');
          }
        }
        else {
          $this->out('No updates found');
        }

    }

    public function doExecute(){

      if( $this->input->get('f', $this->input->get('fetch')) ){
        $this->doFetchUpdates();
      }

      if(
        $this->input->get('l', $this->input->get('list'))
        ||
        $this->input->get('x', $this->input->get('export'))
        ||
        $this->input->get('u', $this->input->get('update'))
        ){
        $this->doIterateUpdates();
      }

    }

  }

// Trigger Execution
  JApplicationCli::getInstance('AutoUpdateCron')
    ->execute();
