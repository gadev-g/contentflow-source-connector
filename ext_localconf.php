<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

$exportEndpoint = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(
    'EXT:contentflow_source_connector/Classes/Eid/ExportEndpoint.php'
);

if (is_string($exportEndpoint) && '' !== $exportEndpoint && is_file($exportEndpoint)) {
    $GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['contentflow_migration_export'] = $exportEndpoint;
}

unset($exportEndpoint);
