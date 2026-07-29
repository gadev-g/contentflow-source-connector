<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['contentflow_migration_export']
    = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('contentflow_source_connector')
    .'Classes/Eid/ExportEndpoint.php';
