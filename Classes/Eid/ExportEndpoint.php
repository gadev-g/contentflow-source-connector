<?php

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Utility\EidUtility;

EidUtility::initTCA();

try {
    $configuration = contentflowSourceConfiguration();
    $action = isset($_GET['contentflow_action']) ? (string) $_GET['contentflow_action'] : 'export';

    if ('media' === $action) {
        contentflowSourceDownloadMedia($configuration);
        exit;
    }

    contentflowSourceExport($configuration);
} catch (Exception $exception) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }

    contentflowSourceJson(array(
        'error' => array(
            'code' => 'source_export_failed',
            'message' => $exception->getMessage(),
        ),
    ));
}

function contentflowSourceExport(array $configuration)
{
    if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) {
        http_response_code(405);
        throw new RuntimeException('Only POST requests are accepted.');
    }

    contentflowSourceValidateToken($configuration);

    $payload = json_decode(file_get_contents('php://input'), true);
    $sourceUrl = is_array($payload) && isset($payload['source_url'])
        ? trim((string) $payload['source_url'])
        : '';

    if (false === filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        http_response_code(422);
        throw new RuntimeException('source_url is required.');
    }

    $requestHost = strtolower(contentflowSourceRequestHost());
    $sourceHost = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

    if ('' === $requestHost || $requestHost !== $sourceHost) {
        http_response_code(422);
        throw new RuntimeException('The source URL must belong to this TYPO3 host.');
    }

    $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    $pagesConnection = $connectionPool->getConnectionForTable('pages');
    $pageUid = contentflowSourceResolvePageUid($pagesConnection, $sourceUrl);

    if ($pageUid <= 0) {
        http_response_code(404);
        throw new RuntimeException('The source URL could not be resolved to a visible TYPO3 page.');
    }

    $page = $pagesConnection->fetchAssoc(
        'SELECT * FROM pages WHERE uid = ? AND deleted = 0 AND hidden = 0',
        array($pageUid)
    );

    if (!is_array($page)) {
        http_response_code(404);
        throw new RuntimeException('The TYPO3 page is not readable.');
    }

    $contentConnection = $connectionPool->getConnectionForTable('tt_content');
    $records = $contentConnection->fetchAll(
        'SELECT * FROM tt_content WHERE pid = ? AND deleted = 0 AND hidden = 0'
        .' AND sys_language_uid IN (0, -1) ORDER BY colPos, sorting',
        array($pageUid)
    );
    $counter = 0;
    $elements = array();
    $media = array();

    foreach ($records as $record) {
        $exported = contentflowSourceExportRecord(
            'tt_content',
            $record,
            $connectionPool,
            $configuration,
            0,
            $counter
        );
        $elements[] = $exported;
        $media = array_merge($media, contentflowSourceCollectMedia($exported));
    }

    contentflowSourceJson(array(
        'schema_version' => '1.0',
        'source' => array(
            'url' => $sourceUrl,
            'page_uid' => (int) $page['uid'],
            'title' => isset($page['title']) ? (string) $page['title'] : '',
            'mode' => 'typo3-8-source-connector',
        ),
        'page' => contentflowSourceEditableFields('pages', $page),
        'elements' => $elements,
        'media' => $media,
        'exported_at' => gmdate('c'),
    ));
}

function contentflowSourceExportRecord(
    $table,
    array $record,
    $connectionPool,
    array $configuration,
    $depth,
    &$counter
) {
    ++$counter;
    $maximum = max(1, isset($configuration['maxExportRecords']) ? (int) $configuration['maxExportRecords'] : 500);

    if ($counter > $maximum) {
        http_response_code(413);
        throw new RuntimeException('The page exceeds the configured export record limit.');
    }

    $relations = array();
    $media = array();

    if ($depth < 5) {
        $columns = isset($GLOBALS['TCA'][$table]['columns']) ? $GLOBALS['TCA'][$table]['columns'] : array();

        foreach ($columns as $field => $definition) {
            $config = isset($definition['config']) && is_array($definition['config'])
                ? $definition['config']
                : array();
            $foreignTable = isset($config['foreign_table']) ? (string) $config['foreign_table'] : '';
            $foreignField = isset($config['foreign_field']) ? (string) $config['foreign_field'] : '';

            if ('sys_file_reference' === $foreignTable) {
                $media = array_merge(
                    $media,
                    contentflowSourceMediaReferences(
                        $table,
                        (int) $record['uid'],
                        (string) $field,
                        $connectionPool,
                        $configuration
                    )
                );
                continue;
            }

            if ('' === $foreignTable || '' === $foreignField || !isset($GLOBALS['TCA'][$foreignTable])) {
                continue;
            }

            $childConnection = $connectionPool->getConnectionForTable($foreignTable);
            $control = isset($GLOBALS['TCA'][$foreignTable]['ctrl'])
                ? $GLOBALS['TCA'][$foreignTable]['ctrl']
                : array();
            $deleteField = !empty($control['delete']) ? (string) $control['delete'] : '';
            $sortField = !empty($control['sortby']) ? (string) $control['sortby'] : 'uid';
            $sql = 'SELECT * FROM '.$foreignTable.' WHERE '.$foreignField.' = ?';

            if ('' !== $deleteField) {
                $sql .= ' AND '.$deleteField.' = 0';
            }

            $children = $childConnection->fetchAll(
                $sql.' ORDER BY '.$sortField,
                array((int) $record['uid'])
            );
            $relations[$field] = array();

            foreach ($children as $child) {
                $relations[$field][] = contentflowSourceExportRecord(
                    $foreignTable,
                    $child,
                    $connectionPool,
                    $configuration,
                    $depth + 1,
                    $counter
                );
            }
        }
    }

    return array(
        'source_table' => $table,
        'source_uid' => (int) $record['uid'],
        'type' => isset($record['CType']) ? (string) $record['CType'] : $table,
        'column' => isset($record['colPos']) ? (int) $record['colPos'] : 0,
        'sorting' => isset($record['sorting']) ? (int) $record['sorting'] : 0,
        'fields' => contentflowSourceEditableFields($table, $record),
        'relations' => $relations,
        'media' => $media,
    );
}

function contentflowSourceMediaReferences(
    $table,
    $recordUid,
    $field,
    $connectionPool,
    array $configuration
) {
    $referenceConnection = $connectionPool->getConnectionForTable('sys_file_reference');
    $references = $referenceConnection->fetchAll(
        'SELECT * FROM sys_file_reference WHERE tablenames = ? AND uid_foreign = ?'
        .' AND fieldname = ? AND deleted = 0 AND hidden = 0 ORDER BY sorting_foreign, uid',
        array($table, $recordUid, $field)
    );
    $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
    $media = array();

    foreach ($references as $reference) {
        try {
            $file = $resourceFactory->getFileObject((int) $reference['uid_local']);
            $size = (int) $file->getSize();
            $maximumSize = max(
                1,
                isset($configuration['maxMediaBytes']) ? (int) $configuration['maxMediaBytes'] : 20000000
            );

            if ($size <= 0 || $size > $maximumSize) {
                continue;
            }

            $contents = $file->getContents();
            $expires = time() + 3600;
            $signature = contentflowSourceMediaSignature((int) $file->getUid(), $expires, $configuration);
            $metadata = array();

            foreach (array('title', 'description', 'alternative', 'link', 'crop') as $metadataField) {
                if (isset($reference[$metadataField])) {
                    $metadata[$metadataField] = (string) $reference[$metadataField];
                }
            }

            $media[] = array(
                'source_file_uid' => (int) $file->getUid(),
                'field' => $field,
                'name' => (string) $file->getName(),
                'mime_type' => (string) $file->getMimeType(),
                'size' => $size,
                'sha256' => hash('sha256', $contents),
                'metadata' => $metadata,
                'download_url' => contentflowSourceBaseUrl()
                    .'/?eID=contentflow_migration_export&contentflow_action=media'
                    .'&file='.$file->getUid()
                    .'&expires='.$expires
                    .'&signature='.rawurlencode($signature),
            );
        } catch (Exception $exception) {
            continue;
        }
    }

    return $media;
}

function contentflowSourceDownloadMedia(array $configuration)
{
    if ('GET' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) {
        http_response_code(405);
        throw new RuntimeException('Only GET requests are accepted for media downloads.');
    }

    $fileUid = isset($_GET['file']) ? (int) $_GET['file'] : 0;
    $expires = isset($_GET['expires']) ? (int) $_GET['expires'] : 0;
    $signature = isset($_GET['signature']) ? (string) $_GET['signature'] : '';
    $expected = contentflowSourceMediaSignature($fileUid, $expires, $configuration);

    if ($fileUid <= 0 || $expires < time() || '' === $signature || !hash_equals($expected, $signature)) {
        http_response_code(403);
        throw new RuntimeException('The media link is invalid or expired.');
    }

    try {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $file = $resourceFactory->getFileObject($fileUid);
        $size = (int) $file->getSize();
        $maximumSize = max(
            1,
            isset($configuration['maxMediaBytes']) ? (int) $configuration['maxMediaBytes'] : 20000000
        );

        if ($size <= 0 || $size > $maximumSize) {
            http_response_code(413);
            throw new RuntimeException('The media file exceeds the configured limit.');
        }

        header('Content-Type: '.$file->getMimeType());
        header('Content-Length: '.$size);
        header('Content-Disposition: attachment; filename="'.addslashes($file->getName()).'"');
        echo $file->getContents();
    } catch (Exception $exception) {
        if (http_response_code() < 400) {
            http_response_code(404);
        }

        throw $exception;
    }
}

function contentflowSourceResolvePageUid($connection, $sourceUrl)
{
    $query = parse_url($sourceUrl, PHP_URL_QUERY);
    parse_str($query ? $query : '', $parameters);

    if (!empty($parameters['id']) && ctype_digit((string) $parameters['id'])) {
        return (int) $parameters['id'];
    }

    $path = trim(rawurldecode((string) parse_url($sourceUrl, PHP_URL_PATH)), '/');
    $segment = basename($path);
    $columns = $connection->getSchemaManager()->listTableColumns('pages');
    $conditions = array();
    $values = array();

    foreach (array('alias', 'tx_realurl_pathsegment') as $field) {
        if (isset($columns[$field])) {
            $conditions[] = $field.' = ?';
            $values[] = $segment;
        }
    }

    if (empty($conditions)) {
        return 0;
    }

    $row = $connection->fetchAssoc(
        'SELECT uid FROM pages WHERE deleted = 0 AND hidden = 0 AND ('.implode(' OR ', $conditions).')',
        $values
    );

    return is_array($row) ? (int) $row['uid'] : 0;
}

function contentflowSourceEditableFields($table, array $record)
{
    $technicalFields = array(
        'uid',
        'pid',
        'tstamp',
        'crdate',
        'cruser_id',
        'deleted',
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'sorting',
        'l18n_parent',
        'l10n_source',
    );
    $fields = array();

    foreach ($record as $field => $value) {
        if (
            in_array($field, $technicalFields, true)
            || !isset($GLOBALS['TCA'][$table]['columns'][$field])
            || (!is_scalar($value) && null !== $value)
        ) {
            continue;
        }

        $fields[$field] = null === $value ? '' : (string) $value;
    }

    return $fields;
}

function contentflowSourceValidateToken(array $configuration)
{
    $expectedHash = isset($configuration['migrationTokenHash'])
        ? strtolower(trim((string) $configuration['migrationTokenHash']))
        : '';
    $authorization = '';

    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
        if (!empty($_SERVER[$key])) {
            $authorization = (string) $_SERVER[$key];
            break;
        }
    }

    $token = preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
        ? trim($matches[1])
        : '';

    if (
        !preg_match('/^[a-f0-9]{64}$/', $expectedHash)
        || '' === $token
        || !hash_equals($expectedHash, hash('sha256', $token))
    ) {
        http_response_code(401);
        throw new RuntimeException('Invalid migration token.');
    }
}

function contentflowSourceConfiguration()
{
    $serialized = isset($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['contentflow_source_connector'])
        ? $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['contentflow_source_connector']
        : '';
    $configuration = @unserialize($serialized);

    return is_array($configuration) ? $configuration : array();
}

function contentflowSourceCollectMedia(array $record)
{
    $media = isset($record['media']) && is_array($record['media']) ? $record['media'] : array();
    $relations = isset($record['relations']) && is_array($record['relations'])
        ? $record['relations']
        : array();

    foreach ($relations as $children) {
        if (!is_array($children)) {
            continue;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $media = array_merge($media, contentflowSourceCollectMedia($child));
            }
        }
    }

    return $media;
}

function contentflowSourceMediaSignature($fileUid, $expires, array $configuration)
{
    $secret = isset($configuration['mediaSigningSecret'])
        ? trim((string) $configuration['mediaSigningSecret'])
        : '';

    if ('' === $secret) {
        $secret = isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'])
            ? (string) $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
            : '';
    }

    return hash_hmac('sha256', $fileUid.':'.$expires, $secret);
}

function contentflowSourceBaseUrl()
{
    $scheme = !empty($_SERVER['HTTPS']) && 'off' !== strtolower((string) $_SERVER['HTTPS'])
        ? 'https'
        : 'http';

    return $scheme.'://'.contentflowSourceRequestHost();
}

function contentflowSourceRequestHost()
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';

    return preg_replace('/:\d+$/', '', $host);
}

function contentflowSourceJson(array $payload)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload);
}
