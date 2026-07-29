<?php

$EM_CONF[$_EXTKEY] = array(
    'title' => 'ContentFlow Source Connector',
    'description' => 'Read-only migration source connector for TYPO3 8.7 installations.',
    'category' => 'services',
    'author' => 'GA DEV - Ahmet Gürer',
    'author_email' => 'info@ga-dev.de',
    'state' => 'beta',
    'clearCacheOnLoad' => 1,
    'version' => '0.1.0',
    'constraints' => array(
        'depends' => array(
            'typo3' => '8.7.0-8.7.99',
            'php' => '7.0.0-7.4.99',
        ),
        'conflicts' => array(),
        'suggests' => array(),
    ),
);
