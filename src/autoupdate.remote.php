<?php

// Plaintext Output
  header('Content-type: text/plain');

// Import Configuration
  if( is_readable('autoupdate.remoteConfig.php') ){
    include 'autoupdate.remoteConfig.php';
  }

// Filter Required
  if( empty($ipFilter) && empty($userFilter) ){
    header('HTTP/1.0 403 Forbidden'); // . $_SERVER['REMOTE_ADDR']);
    die('HTTP/1.0 403 Forbidden');
  }

// Simple IP Filter
  if( !empty($ipFilter) && !in_array($_SERVER['REMOTE_ADDR'], $ipFilter) ){
    header('HTTP/1.0 401 Unauthorized');
    die('HTTP/1.0 401 Unauthorized');
  }

// User Auth Filter
  if( !empty($userFilter) ){

    // Load Environment

      // Set parent system flag
        define('_JEXEC', 1);
      // Define ase
        define('JPATH_BASE', dirname(getcwd()));
      // Load system defines
        if( file_exists(JPATH_BASE . '/defines.php') ){
          require_once JPATH_BASE . '/defines.php';
        }
      // Load defaut defines
        if( !defined('_JDEFINES') ){
          require_once JPATH_BASE . '/includes/defines.php';
        }
      // Load application
        require_once JPATH_BASE . '/includes/framework.php';
        JFactory::getApplication('cms');
      // Required Libraries
        jimport('joomla.user.authentication');

    // User Auth Check

      $headers = getallheaders();
      if( !empty($headers['Authorization']) ){
        $headerAuth = explode(' ', $headers['Authorization'], 2);
        $authCredentials = array_combine(array('username', 'password'), explode(':', base64_decode(end($headerAuth)), 2));
        if( is_array($userFilter) && !in_array($authCredentials['username'], $userFilter) ){
          header('HTTP/1.0 401 Unauthorized');
          die('HTTP/1.0 401 Unauthorized');
        }
        $authResult = JAuthentication::getInstance()->authenticate($authCredentials);
        if( !$authResult || $authResult->status != 1 ){
          header('HTTP/1.0 401 Unauthorized');
          die('HTTP/1.0 401 Unauthorized');
        }
      }
      else {
        header('HTTP/1.0 400 Bad Request');
        die('HTTP/1.0 400 Bad Request');
      }

  }

// Prepare CLI Requirements
  define('STDIN', fopen('php://input', 'r'));
  define('STDOUT', fopen('php://output', 'w'));
  $_SERVER['argv'] = array('autoupdate.php');
  $mQuery = array_merge($_GET, $_POST);
  foreach( $mQuery AS $k => $v ){
    $_SERVER['argv'][] = '-' . $k;
    if( strlen($v) )
      $_SERVER['argv'][] = $v;
  }

// Include / Execute CLI Class
  include 'autoupdate.php';