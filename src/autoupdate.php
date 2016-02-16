<?php

/**
 *
 * This is a CLI Script only
 *   /usr/bin/php /path/to/site/cli/autoupdate.php
 *
 * For Help
 *   php autoupdate.php -h
 *
 */

// Set Version
  const _JoomlaCliAutoUpdateVersion = '0.2.0';

/**
 * 869: joomla\application\web
 */
  if( !isset($_SERVER['HTTP_HOST']) )
    $_SERVER['HTTP_HOST'] = 'cms';

/**
 * 11: includes\framework.php
 */
  if( !isset($_SERVER['HTTP_USER_AGENT']) )
    $_SERVER['HTTP_USER_AGENT'] = 'cms';

// Set parent system flag
  if( !defined('_JEXEC') ){
    define('_JEXEC', 1);
  }

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

// Load Framework
  require_once JPATH_BASE . '/includes/framework.php';

// Update Error Reporting
  error_reporting(E_ALL ^ E_WARNING ^ E_NOTICE ^ E_STRICT ^ E_DEPRECATED);
  ini_set('display_errors', 1);
  set_time_limit(0);

// Iniitialize Application
  if( version_compare( JVERSION, '3.2.0', '>=' ) ){
    JFactory::getApplication('cms');
  }
  else if( version_compare( JVERSION, '3.1.0', '>=' ) ){
    JFactory::getApplication('site');
  }
  else {
    JFactory::getApplication('administrator');
  }

// Load the configuration
  require_once JPATH_CONFIGURATION . '/configuration.php';

// Required Libraries
  jimport('joomla.updater.update');
  jimport('joomla.application.component.helper');

/**
 * This script will download and install all available updates.
 *
 * @since  3.4
 */
  class JoomlaCliAutoUpdate extends JApplicationCli {

    /**
     * [$__outputBuffer description]
     * @var null
     */
    public $__outputBuffer = null;
    public $db             = null;
    public $updater        = null;
    public $installer      = null;
    public $config         = null;

    /**
     * [__construct description]
     * @param JInputCli|null   $input      [description]
     * @param JRegistry|null   $config     [description]
     * @param JDispatcher|null $dispatcher [description]
     */
    public function __construct(JInputCli $input = null, JRegistry $config = null, JDispatcher $dispatcher = null){

      // CLI Constructor
        parent::__construct($input, $config, $dispatcher);

      // Error Handlers
        JError::setErrorHandling(E_NOTICE, 'callback', array($this, 'throwNotice'));
        JError::setErrorHandling(E_WARNING, 'callback', array($this, 'throwWarning'));
        JError::setErrorHandling(E_ERROR, 'callback', array($this, 'throwError'));

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

    /**
     * [throwNotice description]
     * @param  [type] $error [description]
     * @return [type]        [description]
     */
    public function throwNotice( $error ){
      $this->out('Notice #' . $error->getCode() .' - ' . JText::_($error->getMessage()));
    }

    /**
     * [throwWarning description]
     * @param  [type] $error [description]
     * @return [type]        [description]
     */
    public function throwWarning( $error ){
      $this->out('Warning #' . $error->getCode() .' - ' . JText::_($error->getMessage()));
    }

    /**
     * [throwError description]
     * @param  [type] $error [description]
     * @return [type]        [description]
     */
    public function throwError( $error ){
      $this->out('Error #' . $error->getCode() .' - ' . JText::_($error->getMessage()));
      die();
    }

    /**
     * [doPurgeUpdatesCache description]
     * @return [type] [description]
     */
    public function doPurgeUpdatesCache(){

      // Purge Updates Table
        $this->db
          ->setQuery(
            $this->db->getQuery(true)
              ->delete($this->db->quoteName('#__updates'))
              )
          ->execute();
        $this->out('Purged Updates');

      // Reset Cache
        $this->db
          ->setQuery(
            $this->db->getQuery(true)
              ->update($this->db->quoteName('#__update_sites'))
              ->set($this->db->quoteName('last_check_timestamp') . ' = 0')
              )
          ->execute();
        $this->out('Reset Update Cache');

      // Floor Cache Timeout
        if( $this->installer ){
        $this->installer->params->set('cachetimeout', 0);
        }

    }

    /**
     * [doFetchUpdates description]
     * @return [type] [description]
     */
    public function doFetchUpdates(){

      // Get the update cache time
        $cache_timeout = ($this->installer ? $this->installer->params->get('cachetimeout', 6, 'int') : 6);
        $cache_timeout = 3600 * $cache_timeout;

      // Find all updates
        $this->updater->findUpdates(0, $cache_timeout);
        $this->out('Fetched Updates');

    }

    /**
     * [getUpdateRows description]
     * @param  [type] $lookup [description]
     * @param  [type] $start  [description]
     * @param  [type] $limit  [description]
     * @return [type]         [description]
     */
    public function getUpdateRows( $lookup = null, $start = null, $limit = null ){

      // Prepare Query
        $query = $this->db->getQuery(true)
          ->select('*')
          ->from('#__updates')
          ->where($this->db->quoteName('extension_id') . ' != ' . $this->db->quote(0));

      // Prepare Filter
        if( is_numeric($lookup) ){
          $lookup = array('extension_id' => $lookup);
        }
        else if( is_string($lookup) ){
          $lookup = array('element' => $lookup);
        }
        else if( is_array($lookup) ){
          $lookup = (array)$lookup;
        }
        if( $lookup ){
          foreach( $lookup AS $key => $val ){
            $query->where($this->db->quoteName( $key ) . ' = ' . $this->db->quote($val));
          }
        }

      // Query
        return
          $this->db
            ->setQuery($query, $start, $limit)
            ->loadObjectList();

    }

    /**
     * [doInstallUpdate description]
     * @param  [type] $update_id   [description]
     * @param  [type] $build_url   [description]
     * @param  [type] $package_url [description]
     * @return [type]              [description]
     */
    public function doInstallUpdate( $update_id, $build_url = null, $package_url = null ){

      // Load Build XML
        if( $update_id || $build_url ){
          if( $update_id ){
            $this->out('Processing Update #'. $update_id);
            $updateRow = JTable::getInstance('update');
            $updateRow->load( $update_id );
            $build_url = $updateRow->detailsurl;
          }
          else if( $parse_url = parse_url( $build_url ) ){
            $this->out('Processing Update from '. $parse_url['host']);
          }
        }
        if( $build_url ){
          $update = new JUpdate();
          if( $this->installer && defined('JUpdater::STABILITY_STABLE') ){
            $update->loadFromXml($build_url, $this->installer->params->get('minimum_stability', JUpdater::STABILITY_STABLE, 'int'));
          }
          else {
            $update->loadFromXml($build_url);
          }
          if( !empty($updateRow->extra_query) ){
            $update->set('extra_query', $updateRow->extra_query);
          }
        }

      // Pull Packge URL from Build
        if( isset($update) && empty($package_url) ){
          $package_url = $update->downloadurl->_data;
          if( $extra_query = $update->get('extra_query') ){
            $package_url .= (strpos($package_url, '?') === false) ? '?' : '&amp;';
            $package_url .= $extra_query;
          }
        }

      // Download
        $tmpPath = $this->config->get('tmp_path');
        if( !is_writeable($tmpPath) ){
          $tmpPath = JPATH_BASE . '/tmp';
        }
        $this->out(' - Download ' . $package_url);
        $p_file = JInstallerHelper::downloadPackage($package_url);
        if( $p_file && is_file($tmpPath . '/' . $p_file) ){
          $filePath = $tmpPath . '/' . $p_file;
        }
        else {
          $this->out(' - Download Failed, Attempting alternate download method');
          $urlFile = preg_replace('/^.*\/(.*?)$/', '$1', $package_url);
          $filePath = $tmpPath . '/' . $urlFile;
          if( $fileHandle = @fopen($filePath, 'w+') ){
            $curl = curl_init($package_url);
            curl_setopt_array($curl, [
              CURLOPT_URL            => $package_url,
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
          $this->out(' - Download Failed / File not found');
          return false;
        }

      // Extracting Package
        $this->out(' - Extracting Package');
        $package = JInstallerHelper::unpack($filePath);
        if( !$package ){
          $this->out(' - Extract Failed');
          JInstallerHelper::cleanupInstall($filePath);
          return false;
        }

      // Install the package
        $this->out(' - Installing ' . $package['dir']);
        $installer = JInstaller::getInstance();
        // $update->set('type', $package['type']);
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

    /**
     * [doIterateUpdates description]
     * @return [type] [description]
     */
    public function doIterateUpdates(){

      // Build Update Filter
        $update_lookup = array();

      // All Items
        if( $this->input->get('a', $this->input->get('all')) ){
        }

      // Core Items
        if( $this->input->get('c', $this->input->get('core')) ){
          $lookup = array(
            'type'    => 'file',
            'element' => 'joomla'
            );
          if( $version = $this->input->get('v', $this->input->get('version')) ){
            $lookup['version'] = $version;
          }
          $update_lookup[] = $lookup;
        }

      // Extension Lookup
        if( $extension_lookup = $this->input->get('e', $this->input->get('extension')) ){
          if( is_numeric($extension_lookup) ){
            $lookup = array(
              'extension_id' => (int)$extension_lookup
              );
          }
          else {
            $lookup = array(
              'element' => (string)$extension_lookup
              );
          }
          if( $type = $this->input->get('t', $this->input->get('type')) ){
            $lookup['type'] = $type;
          }
          if( $version = $this->input->get('v', $this->input->get('version')) ){
            $lookup['version'] = $version;
          }
          $update_lookup[] = $lookup;
        }

      // Update ID
        if( $update_id = $this->input->get('i', $this->input->get('id')) ){
          $update_lookup[] = array(
            'update_id' => $update_id
            );
        }

      // List / Export / Process Updates
        $update_rows = $this->getUpdateRows( array_shift($update_lookup) );
        if( $update_rows ){
          $do_list     = $this->input->get('l', $this->input->get('list'));
          $do_export   = $this->input->get('x', $this->input->get('export'));
          $do_update   = $this->input->get('u', $this->input->get('update'));
          $export_data = null;
          if( $do_export ){
            $export_data = array(
              'updates' => array()
              );
          }
          else if( $do_list ){
            $this->out(implode('',array(
              str_pad('uid', 10, ' ', STR_PAD_RIGHT),
              str_pad('eid', 10, ' ', STR_PAD_RIGHT),
              str_pad('element', 30, ' ', STR_PAD_RIGHT),
              str_pad('type', 10, ' ', STR_PAD_RIGHT),
              str_pad('version', 10, ' ', STR_PAD_RIGHT),
              str_pad('installed', 10, ' ', STR_PAD_RIGHT)
              )));
          }
          $run_update_rows = array();
          do {
            foreach( $update_rows AS $update_row ){
              $extension = $this->db
                ->setQuery("
                  SELECT *
                  FROM `#__extensions`
                  WHERE `extension_id` = '". (int)$update_row->extension_id ."'
                  ")
                ->loadObject();
              $update_row->installed_version = null;
              if( $extension->manifest_cache && $extension_manifest = json_decode($extension->manifest_cache) ){
                $update_row->installed_version = $extension_manifest ? $extension_manifest->version : null;
              }
              if( $do_export ){
                $export_data['updates'][] = $update_row;
              }
              else if( $do_list ){
                $this->out(implode('',array(
                  str_pad($update_row->update_id, 10, ' ', STR_PAD_RIGHT),
                  str_pad($update_row->extension_id, 10, ' ', STR_PAD_RIGHT),
                  str_pad($update_row->element, 30, ' ', STR_PAD_RIGHT),
                  str_pad($update_row->type, 10, ' ', STR_PAD_RIGHT),
                  str_pad($update_row->version, 10, ' ', STR_PAD_RIGHT),
                  str_pad($update_row->installed_version, 10, ' ', STR_PAD_RIGHT)
                  )));
              }
            }
            if( $do_update ){
              $run_update_rows += $update_rows;
            }
          } while(
            count($update_lookup)
            && $update_rows = $this->getUpdateRows( array_shift($update_lookup) )
            );
          if( count($run_update_rows) ){
            foreach( $run_update_rows AS $update_row ){
              if( !$this->doInstallUpdate( $update_row->update_id ) ){
                return false;
              }
            }
            $this->out('Update processing complete');
          }
          if( isset($export_data) ){
            $this->out( $export_data );
          }
        }
        else {
          $this->out('No updates found');
        }

    }

    /**
     * [startOutputBuffer description]
     * @return [type] [description]
     */
    public function startOutputBuffer(){
      $this->__outputBuffer = array(
        'status'  => 200,
        'message' => 'Success',
        'log'     => array(),
        'data'    => array()
        );
    }

    /**
     * [dumpOutputBuffer description]
     * @return [type] [description]
     */
    public function dumpOutputBuffer(){
      return parent::out( json_encode($this->__outputBuffer) );
    }

    /**
     * [out description]
     * @param  string  $text [description]
     * @param  boolean $nl   [description]
     * @return [type]        [description]
     */
    public function out( $text = '', $nl = true ){
      if( isset($this->__outputBuffer) ){
        if( is_string($text) ){
          $this->__outputBuffer['log'][] = $text;
        }
        else {
          $this->__outputBuffer['data'] = array_merge( $this->__outputBuffer['data'], $text );
        }
        return $this;
      }
      return parent::out( $text, $nl );
    }

    /**
     * [doExecute description]
     * @return [type] [description]
     */
    public function doExecute(){

      if( $this->input->get('x', $this->input->get('export')) ){
        $this->startOutputBuffer();
      }

      if( $this->input->get('p', $this->input->get('purge')) ){
        $this->doPurgeUpdatesCache();
      }

      if( $this->input->get('f', $this->input->get('fetch')) ){
        $this->doFetchUpdates();
      }

      if(
        $this->input->get('l', $this->input->get('list'))
        ||
        $this->input->get('u', $this->input->get('update'))
        ){
        $this->doIterateUpdates();
      }

      $build_url = $this->input->getRaw('B', $this->input->getRaw('build-xml'));
      if( $build_url && $build_url != 1 ){
        $this->doInstallUpdate( null, $build_url );
      }

      $package_url = $this->input->getRaw('P', $this->input->getRaw('package-archive'));
      if( $package_url && $package_url != 1 ){
        $this->doInstallUpdate( null, null, $package_url );
      }

      if( $this->input->get('h', $this->input->get('help')) ){
        $this->doEchoHelp();
      }

      if( $this->input->get('x', $this->input->get('export')) ){
        $this->dumpOutputBuffer();
      }

    }

    /**
     * [doEchoHelp description]
     * @return [type] [description]
     */
    public function doEchoHelp(){
      $version = _JoomlaCliAutoUpdateVersion;
      echo <<<EOHELP
Joomla! CLI Autoupdate by Webuddha v{$version}
This script can be used to examine the extension of a local Joomla!
installation, fetch available updates, download and install update packages.

Operations
  -f, --fetch                 Run Fetch
  -u, --update                Run Update
  -l, --list                  List Updates
  -p, --purge                 Purge Updates
  -P, --package-archive URL   Install from Package Archive
  -B, --build-xml URL         Install from Package Build XML

Update Filters
  -i, --id ID                 Update ID
  -a, --all                   All Packages
  -V, --version VER           Version Filter
  -c, --core                  Joomla! Core Packages
  -e, --extension LOOKUP      Extension by ID/NAME
  -t, --type VAL              Type

Additional Flags
  -x, --export                Output in JSON format
  -h, --help                  Help
  -v, --verbose               Verbose

EOHELP;
    }

  }

// Trigger Execution
  JApplicationCli::getInstance('JoomlaCliAutoUpdate')
    ->execute();
