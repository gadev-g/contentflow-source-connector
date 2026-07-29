<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['contentflow_migration_export']
    = 'EXT:contentflow_source_connector/Classes/Eid/ExportEndpoint.php';
