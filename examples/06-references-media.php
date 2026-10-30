<?php namespace ProcessWire;

/** Page references and media with per-image metadata. Adapt selectors to your Page tree. */
return [
    'primary_page' => [
        'type' => 'page',
        'label' => 'Primary page',
        'inputfield' => 'InputfieldSelect',
        'derefAsPage' => 2, // Return a Page or NullPage rather than a collection.
        'findPagesSelector' => 'parent=1',
        'labelFieldName' => 'title',
        'columnWidth' => 50,
    ],
    'related_pages' => [
        'type' => 'page',
        'label' => 'Related pages',
        'inputfield' => 'InputfieldAsmSelect',
        'derefAsPage' => 0, // Return a PageArray, preserving the selected order.
        'findPagesSelector' => 'parent=1',
        'labelFieldName' => 'title',
        'columnWidth' => 50,
    ],
    'show_credits' => [
        'type' => 'checkbox',
        'label' => 'Show image credits',
        'value' => 1,
    ],
    'cover' => [
        'type' => 'image',
        'label' => 'Cover image',
        'textformatters' => ['TextformatterEntities'],
        'extensions' => 'jpg jpeg gif png',
        'maxFiles' => 1, // Formatted reads return a single Pageimage (or null).
    ],
    'gallery' => [
        'type' => 'image',
        'label' => 'Image gallery',
        'textformatters' => ['TextformatterEntities'],
        'extensions' => 'jpg jpeg gif png',
        'maxFiles' => 10,
        'outputFormat' => 1, // Always return a collection, even with one image.
        'customFields' => [ // Each image has its own metadata values.
            'description' => ['type' => 'textarea', 'label' => 'Description'], // Customize the native description input.
            'tags' => ['type' => 'TextTags', 'label' => 'Tags'], // Edit the native tags, not a separate property.
            'copyright' => [
                'type' => 'text', 'label' => 'Copyright', 'columnWidth' => 50,
                'textformatters' => ['TextformatterEntities'],
            ],
            'year' => ['type' => 'integer', 'label' => 'Year', 'columnWidth' => 50],
            'decorative' => ['type' => 'checkbox', 'label' => 'Decorative image'],
            'credit' => [
                'type' => 'text', 'label' => 'Photo credit',
                'showIf' => 'decorative=0, parent.show_credits=1', // Local image metadata and the containing Flex value.
                'textformatters' => ['TextformatterEntities'],
            ],
            'body' => ['type' => 'TinyMCE', 'label' => 'Image notes'],
            'photographer' => [
                'type' => 'page', 'label' => 'Photographer',
                'inputfield' => 'InputfieldSelect', 'derefAsPage' => 2, 'parent_id' => 1,
            ],
        ],
    ],
    'downloads' => [
        'type' => 'file',
        'label' => 'Downloads',
        'textformatters' => ['TextformatterEntities'],
        'extensions' => 'pdf txt zip',
        'maxFiles' => 5,
    ],
];
