<?php

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Utility\EidUtility;

try {
    if (method_exists(EidUtility::class, 'initTCA')) {
        EidUtility::initTCA();
    }

    $configuration = contentflowSourceConfiguration();
    $action = isset($_GET['contentflow_action']) ? (string) $_GET['contentflow_action'] : 'export';

    if ('media' === $action) {
        contentflowSourceDownloadMedia($configuration);
        exit;
    }

    contentflowSourceExport($configuration);
} catch (Throwable $exception) {
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
    $contentColumns = $contentConnection->getSchemaManager()->listTableColumns('tt_content');
    $rootCondition = isset($contentColumns['tx_gridelements_container'])
        ? ' AND (tx_gridelements_container = 0 OR tx_gridelements_container IS NULL)'
        : '';
    $records = $contentConnection->fetchAll(
        'SELECT * FROM tt_content WHERE pid = ? AND deleted = 0 AND hidden = 0'
        .' AND sys_language_uid IN (0, -1)'.$rootCondition.' ORDER BY colPos, sorting',
        array($pageUid)
    );
    $counter = 0;
    $elements = array();
    $media = array();

    foreach ($records as $record) {
        $expandedRecords = contentflowSourceExpandShortcutRecords($record, $connectionPool);

        foreach ($expandedRecords as $expandedRecord) {
            $exported = contentflowSourceExportRecord(
                'tt_content',
                $expandedRecord,
                $connectionPool,
                $configuration,
                0,
                $counter
            );

            if ((int) $expandedRecord['uid'] !== (int) $record['uid']) {
                $exported['source_reference'] = 'shortcut:'.(int) $record['uid'];
            }

            $elements[] = $exported;
            $media = array_merge($media, contentflowSourceCollectMedia($exported));
        }
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

    if (
        'tt_content' === $table
        && 'shortcut' === (isset($record['CType']) ? (string) $record['CType'] : '')
    ) {
        $shortcut = contentflowSourceResolveShortcut($record, $connectionPool);

        if (is_array($shortcut)) {
            $shortcut['colPos'] = isset($record['colPos']) ? $record['colPos'] : 0;
            $shortcut['sorting'] = isset($record['sorting']) ? $record['sorting'] : 0;
            $shortcut['tx_gridelements_container'] = isset($record['tx_gridelements_container'])
                ? $record['tx_gridelements_container']
                : 0;
            $shortcut['tx_gridelements_columns'] = isset($record['tx_gridelements_columns'])
                ? $record['tx_gridelements_columns']
                : 0;
            $resolved = contentflowSourceExportRecord(
                'tt_content',
                $shortcut,
                $connectionPool,
                $configuration,
                $depth,
                $counter
            );
            $resolved['source_reference'] = 'shortcut:'.(int) $record['uid'];

            return $resolved;
        }
    }

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

        if ('tt_content' === $table) {
            $gridChildren = contentflowSourceGridChildren(
                (int) $record['uid'],
                $connectionPool,
                $configuration,
                $depth + 1,
                $counter
            );

            if (!empty($gridChildren)) {
                $relations['contentflow_grid_children'] = $gridChildren;
            }
        }
    }

    $editableFields = contentflowSourceEditableFields($table, $record);

    return array(
        'source_table' => $table,
        'source_uid' => (int) $record['uid'],
        'type' => isset($record['CType']) ? (string) $record['CType'] : $table,
        'column' => isset($record['colPos']) ? (int) $record['colPos'] : 0,
        'sorting' => isset($record['sorting']) ? (int) $record['sorting'] : 0,
        'fields' => $editableFields,
        'relations' => $relations,
        'media' => $media,
        'linked_files' => contentflowSourceLinkedDocuments(
            $editableFields,
            $configuration
        ),
    );
}

function contentflowSourceResolveShortcut(array $record, $connectionPool)
{
    $records = contentflowSourceShortcutRecords($record, $connectionPool);

    return isset($records[0]) ? $records[0] : null;
}

function contentflowSourceExpandShortcutRecords(array $record, $connectionPool)
{
    if ('shortcut' !== (isset($record['CType']) ? (string) $record['CType'] : '')) {
        return array($record);
    }

    $records = contentflowSourceShortcutRecords($record, $connectionPool);

    if (empty($records)) {
        return array($record);
    }

    foreach ($records as &$resolved) {
        $resolved['colPos'] = isset($record['colPos']) ? $record['colPos'] : 0;
        $resolved['sorting'] = isset($record['sorting']) ? $record['sorting'] : 0;
        $resolved['tx_gridelements_container'] = isset($record['tx_gridelements_container'])
            ? $record['tx_gridelements_container']
            : 0;
        $resolved['tx_gridelements_columns'] = isset($record['tx_gridelements_columns'])
            ? $record['tx_gridelements_columns']
            : 0;
    }

    unset($resolved);

    return $records;
}

function contentflowSourceShortcutRecords(array $record, $connectionPool)
{
    $references = isset($record['records']) ? (string) $record['records'] : '';

    if (!preg_match_all('/(?:^|,)\s*(tt_content|pages)_(\d+)(?=\s*,|$)/', $references, $matches, PREG_SET_ORDER)) {
        return array();
    }

    $records = array();

    foreach ($matches as $match) {
        $table = (string) $match[1];
        $uid = (int) $match[2];

        if ('pages' === $table) {
            $records = array_merge(
                $records,
                contentflowSourcePageContentRecords($uid, $connectionPool)
            );
            continue;
        }

        $connection = $connectionPool->getConnectionForTable('tt_content');
        $row = $connection->fetchAssoc(
            'SELECT * FROM tt_content WHERE uid = ? AND deleted = 0 AND hidden = 0',
            array($uid)
        );

        if (is_array($row)) {
            $records[] = $row;
        }
    }

    return $records;
}

function contentflowSourcePageContentRecords($pageUid, $connectionPool)
{
    $connection = $connectionPool->getConnectionForTable('tt_content');
    $columns = $connection->getSchemaManager()->listTableColumns('tt_content');
    $rootCondition = isset($columns['tx_gridelements_container'])
        ? ' AND (tx_gridelements_container = 0 OR tx_gridelements_container IS NULL)'
        : '';

    return $connection->fetchAll(
        'SELECT * FROM tt_content WHERE pid = ? AND deleted = 0 AND hidden = 0'
        .' AND sys_language_uid IN (0, -1)'.$rootCondition
        .' ORDER BY colPos, sorting',
        array((int) $pageUid)
    );
}

function contentflowSourceGridChildren(
    $parentUid,
    $connectionPool,
    array $configuration,
    $depth,
    &$counter
) {
    $connection = $connectionPool->getConnectionForTable('tt_content');
    $columns = $connection->getSchemaManager()->listTableColumns('tt_content');

    if (!isset($columns['tx_gridelements_container'])) {
        return array();
    }

    $children = $connection->fetchAll(
        'SELECT * FROM tt_content WHERE tx_gridelements_container = ?'
        .' AND deleted = 0 AND hidden = 0 AND sys_language_uid IN (0, -1)'
        .' ORDER BY tx_gridelements_columns, sorting',
        array($parentUid)
    );
    $exported = array();

    foreach ($children as $child) {
        foreach (contentflowSourceExpandShortcutRecords($child, $connectionPool) as $expandedChild) {
            $record = contentflowSourceExportRecord(
                'tt_content',
                $expandedChild,
                $connectionPool,
                $configuration,
                $depth,
                $counter
            );

            if ((int) $expandedChild['uid'] !== (int) $child['uid']) {
                $record['source_reference'] = 'shortcut:'.(int) $child['uid'];
            }

            $exported[] = $record;
        }
    }

    return $exported;
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

function contentflowSourceLinkedDocuments(array $fields, array $configuration)
{
    $documents = array();
    $seen = array();

    foreach ($fields as $value) {
        if (!is_string($value) || '' === trim($value)) {
            continue;
        }

        $links = array();

        if (preg_match_all('/href\s*=\s*(["\'])(.*?)\1/i', $value, $matches)) {
            $links = array_merge($links, $matches[2]);
        }

        if (preg_match_all('/<link\s+([^\s>]+)[^>]*>/i', $value, $matches)) {
            $links = array_merge($links, $matches[1]);
        }

        foreach ($links as $link) {
            $originalHref = html_entity_decode(trim((string) $link), ENT_QUOTES, 'UTF-8');
            $file = contentflowSourceResolveLinkedFile($originalHref);

            if (null === $file || isset($seen[(int) $file->getUid()])) {
                continue;
            }

            $size = (int) $file->getSize();
            $maximumSize = max(
                1,
                isset($configuration['maxMediaBytes']) ? (int) $configuration['maxMediaBytes'] : 20000000
            );

            if (
                $size <= 0
                || $size > $maximumSize
                || 'application/pdf' !== strtolower((string) $file->getMimeType())
            ) {
                continue;
            }

            $contents = $file->getContents();
            $expires = time() + 3600;
            $signature = contentflowSourceMediaSignature((int) $file->getUid(), $expires, $configuration);
            $documents[] = array(
                'source_file_uid' => (int) $file->getUid(),
                'original_href' => $originalHref,
                'name' => (string) $file->getName(),
                'mime_type' => (string) $file->getMimeType(),
                'size' => $size,
                'sha256' => hash('sha256', $contents),
                'download_url' => contentflowSourceBaseUrl()
                    .'/?eID=contentflow_migration_export&contentflow_action=media'
                    .'&file='.$file->getUid()
                    .'&expires='.$expires
                    .'&signature='.rawurlencode($signature),
            );
            $seen[(int) $file->getUid()] = true;
        }
    }

    return $documents;
}

function contentflowSourceResolveLinkedFile($href)
{
    $fileUid = 0;

    if (preg_match('/^file:(\d+)$/i', $href, $match)) {
        $fileUid = (int) $match[1];
    } elseif (preg_match('/^t3:\/\/file\?[^#]*\buid=(\d+)/i', $href, $match)) {
        $fileUid = (int) $match[1];
    }

    try {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        if ($fileUid > 0) {
            return $resourceFactory->getFileObject($fileUid);
        }

        $path = rawurldecode((string) parse_url($href, PHP_URL_PATH));

        if (!preg_match('#(?:^|/)fileadmin/(.+)$#i', $path, $match)) {
            return null;
        }

        $identifier = '/'.ltrim($match[1], '/');

        return $resourceFactory->getFileObjectFromCombinedIdentifier('1:'.$identifier);
    } catch (Exception $exception) {
        return null;
    }
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

    if ('' !== $path) {
        $schemaManager = $connection->getSchemaManager();
        $tableNames = $schemaManager->listTableNames();

        if (in_array('tx_realurl_pathcache', $tableNames, true)) {
            $pathCacheColumns = $schemaManager->listTableColumns('tx_realurl_pathcache');
            $pathConditions = array('pagepath = ?');
            $pathValues = array($path);

            if (isset($pathCacheColumns['language_id'])) {
                $pathConditions[] = 'language_id IN (0, -1)';
            }

            if (isset($pathCacheColumns['expire'])) {
                $pathConditions[] = '(expire = 0 OR expire > ?)';
                $pathValues[] = time();
            }

            $pathCacheRow = $connection->fetchAssoc(
                'SELECT page_id FROM tx_realurl_pathcache WHERE '
                .implode(' AND ', $pathConditions)
                .' ORDER BY page_id DESC',
                $pathValues
            );

            if (is_array($pathCacheRow) && !empty($pathCacheRow['page_id'])) {
                return (int) $pathCacheRow['page_id'];
            }
        }
    }

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
