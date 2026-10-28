<?php namespace ProcessWire;

/** Native Page references and owned File/Image properties. */
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
        'label' => __('Cover image'),
        'extensions' => 'jpg jpeg png webp',
        'maxFiles' => 1,
        'columnWidth' => 50,
    ],
    'media_gallery' => [
        'type' => 'image',
        'label' => __('Image gallery'),
        'extensions' => 'jpg jpeg png webp',
        'maxFiles' => 10,
        'outputFormat' => 1,
        'columnWidth' => 50,
    ],
    'media_downloads' => [
        'type' => 'file',
        'label' => __('Downloads'),
        'extensions' => 'pdf txt zip',
        'maxFiles' => 5,
        'columnWidth' => 100,
    ],
];
