<?php
// phpscripts/bootstrap.php
define('BASE_PATH', dirname(__FILE__));  // C:\D\web\apps\labelcenter\phpscripts\
define('CONF_PATH', realpath(BASE_PATH . '/../../settings/conf.php'));  // apps/settings/conf.php

if (!file_exists(CONF_PATH)) {
    die('CONF_PATH not found: ' . CONF_PATH);
}