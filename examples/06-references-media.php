<?php namespace ProcessWire;

/**
 * Single/multiple Page references and File/Image collections.
 * Adjust findPagesSelector to the Pages that editors should be able to select.
 */
return [
    'ref_primary' => [
        'type' => 'page',
        'label' => __('Primary page'),
        'inputfield' => 'InputfieldSelect',
        'derefAsPage' => 2,
        'findPagesSelector' => 'parent=1',
        'labelFieldName' => 'title',
        'columnWidth' => 50,
    ],
    'ref_related' => [
        'type' => 'page',
        'label' => __('Related pages'),
        'inputfield' => 'InputfieldAsmSelect',
        'derefAsPage' => 0,
        'findPagesSelector' => 'parent=1',
        'labelFieldName' => 'title',
        'columnWidth' => 50,
    ],
    'media_cover' => [
        'type' => 'image',
        'textformatters' => ['TextformatterEntities'],
        'label' => __('Cover image'),
        'extensions' => 'jpg jpeg gif png',
        'maxFiles' => 1,
        'columnWidth' => 50,
    ],
    'media_gallery' => [
        'type' => 'image',
        'textformatters' => ['TextformatterEntities'],
        'label' => __('Image gallery'),
        'extensions' => 'jpg jpeg gif png',
        'customFields' => [
            'description' => ['type' => 'textarea'],
            'tags' => ['type' => 'TextTags'],
            'copyright' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
            'year' => ['type' => 'integer'],
            'decorative' => ['type' => 'checkbox'],
            'credit' => ['type' => 'text', 'showIf' => 'decorative=0'],
            'body' => ['type' => 'TinyMCE'],
            'photographer' => ['type' => 'page', 'inputfield' => 'InputfieldSelect', 'derefAsPage' => 2, 'parent_id' => 1],
        ],
        'maxFiles' => 10,
        'outputFormat' => 1,
        'columnWidth' => 50,
    ],
    'media_downloads' => [
        'type' => 'file',
        'textformatters' => ['TextformatterEntities'],
        'label' => __('Downloads'),
        'extensions' => 'pdf txt zip',
        'maxFiles' => 5,
        'columnWidth' => 100,
    ],
];
