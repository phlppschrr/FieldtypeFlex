# FieldtypeFlex

**Structured content in one ProcessWire field.**

FieldtypeFlex lets you define structured content in PHP or JSON and edit it using native ProcessWire Inputfields. Instead of creating separate fields for every property, you can keep related values together in a single field.

Use it for anything from a simple group of properties to nested content blocks with different types, images and Page references. Values are accessible through the familiar ProcessWire API and can be queried with selectors.

**Version:** 1.0.0-beta.1

https://github.com/user-attachments/assets/eb40e7a9-d51c-47b3-b798-db6acb97651a

[View a static screenshot](docs/images/editor.jpg).

## Features

- **Define structures in code.** Use PHP, JSON or native Inputfield objects. PHP definitions support reusable schemas, translations and page-aware configuration.
- **Native ProcessWire inputs.** Use TinyMCE, images, Page references, multilingual fields, conditional inputs and familiar editor layouts.
- **Native Bridge support.** Use AdminThemeBridge 0.24.0+ without jQuery for native child Inputfields; classic ProcessWire admin themes remain supported.
- **Language alternates.** Define siblings such as `image` and `image_de`; formatted reads select the current language with native fallback. See [Language Alternate Fields](API.md#language-alternate-fields).
- **Structured Objects.** Group related properties into fixed, nested structures without creating separate ProcessWire fields.
- **Typed and nested Rows.** Mix different block types, each with its own fields. Editors can add, duplicate, reorder and hide rows without separate Repeater Pages.
- **Tables.** Use fixed columns with optional editable headings and row labels. Edit files, images, page trees and rich text in a detail row below the cells. See the [table examples](examples/08-tables.php).
- **Files and images.** Use ProcessWire-compatible `Pagefile` and `Pageimage` objects, image resizing and custom metadata for individual files.
- **ProcessWire selectors.** Find pages by properties inside Objects and Rows, including nested values and block types.
- **Schema changes without data loss.** Unknown properties survive normal saves. Use `renamedFrom` and maintenance tools to handle renamed or obsolete properties.

Flex fields also work inside native ProcessWire Repeaters.

## Installation

Requires **ProcessWire 3.0.259+**, **PHP 8.1+**, **MySQL 8.0+ or MariaDB 10.11+**, and **InnoDB**.

1. Copy the module to `/site/modules/FieldtypeFlex/`.
2. In ProcessWire Admin, go to **Modules → Refresh** and install **FieldtypeFlex**.
3. Create a field using FieldtypeFlex and add it to a page template.

Translations for German, French and Spanish are available on the module page.

## Quick start

Let's create a `content` field with an introduction and two types of content blocks: Text and Gallery.

### Define your content

Create a Flex field named `content` and add it to your page template. Make sure InputfieldTinyMCE is installed.

Save this definition as `site/templates/flex-fields/content.php`:

```php
<?php namespace ProcessWire;

return [
    'intro' => [
        'type' => 'TinyMCE',
        'label' => 'Introduction',
    ],
    'blocks' => [
        'type' => 'rows',
        'label' => 'Content blocks',
        'types' => [
            'text' => [
                'label' => 'Text',
                'rowTitle' => '[#{@index}]{.flex-muted} {heading|"Untitled"}',
                'children' => [
                    'heading' => [
                        'type' => 'text',
                        'label' => 'Heading',
                        'textformatters' => ['TextformatterEntities'],
                    ],
                    'body' => [
                        'type' => 'TinyMCE',
                        'label' => 'Text',
                    ],
                ],
            ],
            'gallery' => [
                'label' => 'Gallery',
                'children' => [
                    'images' => [
                        'type' => 'image',
                        'label' => 'Images',
                        'maxFiles' => 10,
                        'textformatters' => ['TextformatterEntities'],
                    ],
                    'layout' => [
                        'type' => 'radios',
                        'label' => 'Layout',
                        'options' => [
                            'grid' => 'Grid',
                            'stacked' => 'Stacked',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
```

### Access your content

Flex properties are accessible through the usual ProcessWire API. These examples assume output formatting is enabled, as it is in page templates:

```php
echo $page->content->intro;

foreach($page->content->blocks as $block) {
    if($block->rowType() === 'text') {
        echo $block->heading;
        echo $block->body;
    } elseif($block->rowType() === 'gallery') {
        echo $block->layout;

        foreach($block->images as $image) {
            echo $image->size(800, 600)->url;
            echo $image->description;
        }
    }
}
```

### Query your content

Flex properties can also be used in ProcessWire selectors:

```php
// Find pages containing a text block with a matching heading.
$matches = $pages->find('content.blocks.text.heading*=Architecture');

// Find pages containing more than one Gallery block.
$withGalleries = $pages->find('content.blocks.gallery.count>1');
```

## Copy and paste rows

Use a row's clipboard menu or a table row's **More row actions** menu to copy
a row or paste above or below it. The collection header menu includes **Paste
from Clipboard**, which appends copied rows even when the collection is empty.
To add a new row, choose a type in **Add item**. Collections with one type
show a direct **Add item** button.

Choose **Select rows** in the collection header. Checkboxes show which rows are
selected. Click a card header or anywhere in a table row to toggle selection.
Use Shift-click to extend the selection to a range. The toolbar below the
collection copies, hides, or marks the selected rows for removal. **Done**
restores previously expanded cards. Copies use current editor values and
collection order. Paste uses the target collection's types, settings and
defaults. Save the page to commit added rows, removed rows, visibility and media.

Clipboard access needs HTTPS and browser permission. When copying across
installations, language values match by language name. Page references resolve
to existing pages with the same full path, parent path and template name. The
destination field's local parent, template, selector and access rules still
apply. Relative rich-text links retain their source URL. Media uses owned
local originals when available. Remote media requires cURL and PHP's global IP
range filter. Downloads use public IPv4 HTTPS URLs without redirects, with file
type, size and time limits. Any skipped contents are reported in the editor.

## Examples and documentation

The [examples](examples/README.md) cover PHP, JSON and native Inputfield definitions, from basic properties to nested Rows, conditional inputs and custom media fields.

See the [API reference](API.md) for supported settings, value types, selectors and methods.

## Current limitations

FieldtypeFlex is still a pre-release. Some ProcessWire features and integrations are not supported:

- SQLite and PostgreSQL databases.
- Native ProcessWire page-version save/restore.
- Page-reference selector traversal from typed Rows, across multiple hops or into unsupported target fields.
- Certain media options, including overwrite mode and native ProcessWire custom-field templates for files and images. TinyMCE drag-and-drop uploads require a regular ProcessWire Image field as their destination.

See [API.md](API.md) for the full compatibility details.

Bug reports, ideas and contributions are welcome. Feel free to [open an issue](https://github.com/phlppschrr/FieldtypeFlex/issues) or see [CONTRIBUTING.md](CONTRIBUTING.md) if you'd like to help.
