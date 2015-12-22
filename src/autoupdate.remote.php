<?php

// Plaintext Output
  header('Content-type: text/plain');

// Import Configuration
  if( is_readable('autoupdate.remoteConfig.php') ){
    include 'autoupdate.remoteConfig.php';
  }

// Filter Required
  if( empty($ipFilter) && empty($userFilter) ){
    die('Authorization Required');
  }

// Simple IP Filter
  if( !empty($ipFilter) && !in_array($_SERVER['REMOTE_ADDR'], $ipFilter) ){
    die('Remote Invalid');
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
      $headers['Authorization'] = 'Basic QWxhZGRpbjpvcGVuIHNlc2FtZQ==';
      if( !empty($headers['Authorization']) ){
        $headerAuth = explode(' ', $headers['Authorization'], 2);
        $authCredentials = array_combine(array('username', 'password'), explode(':', base64_decode(end($headerAuth)), 2));
        $authResult = JAuthentication::getInstance()->authenticate($authCredentials);
        if( !$authResult || $authResult->status != 1 ){
          die('Login Invalid');
        }
      }
      else {
        die('Invalid Authorization');
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