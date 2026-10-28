# FieldtypeFlex

**Structured content in one ProcessWire field.**

FieldtypeFlex is an open-source fieldtype for [ProcessWire](https://processwire.com/) that combines JSON storage, native Inputfields, ProcessWire selectors, File/Image support and typed repeatable content.

Define your field structure in PHP or JSON, edit everything together in Page Edit, and work with individual properties through the familiar ProcessWire API.

**Status:** 0.3.0 pre-release · **License:** MIT

![FieldtypeFlex in the ProcessWire page editor](docs/images/editor.jpg)

## Features

- **One field, one JSON value.** Store related properties together without creating a separate ProcessWire field for every value.
- **ProcessWire selectors.** Query individual properties, typed rows and collection counts with `$pages->find()`.
- **Files and images.** Manage media directly in Flex and use ProcessWire-compatible `Pagefile` and `Pageimage` objects, including image resizing and variations.
- **Typed, nested rows.** Build sortable content blocks with different schemas, similar in concept to RepeaterMatrix. Nest row collections to create more complex structures.

Flex uses native ProcessWire Inputfields for editing, including validation, conditional dependencies, Page references, TinyMCE and multilingual text. Flex fields can also be used inside native ProcessWire Repeater items.

## Installation

Requires **ProcessWire 3.0.259+**, **PHP 8.1+**, **MySQL 8.0+ or MariaDB 10.11+**, and **InnoDB**.

Place the module in `/site/modules/FieldtypeFlex/`, then go to **Modules → Refresh** in ProcessWire Admin and install **FieldtypeFlex**.

## Quick start

Create a Flex field named `content` and add it to a page template.

For the rich-text example below, also create a regular ProcessWire Textarea field named `rich_text_source`, configured to use TinyMCE. Flex will inherit its editor settings and textformatters; the source field does not need to be added to the page template.

Create `site/templates/flex-fields/content.php`:

```php
<?php namespace ProcessWire;

return [
    'heading' => [
        'type'  => 'text',
        'label' => __('Heading'),
    ],
    'hero' => [
        'type'     => 'image',
        'label'    => __('Hero image'),
        'maxFiles' => 1,
    ],
    'downloads' => [
        'type'       => 'file',
        'label'      => __('Downloads'),
        'extensions' => 'pdf txt zip',
        'maxFiles'   => 5,
    ],
    'blocks' => [
        'type'        => 'rows',
        'label'       => __('Content blocks'),
        'addRowLabel' => __('Add block'),
        'types'       => [
            'text' => [
                'label'    => __('Text'),
                'rowTitle' => '{heading}',
                'children' => [
                    'heading' => [
                        'type'  => 'text',
                        'label' => __('Heading'),
                    ],
                    'body' => [
                        'type'         => 'TinyMCE',
                        'inheritField' => 'rich_text_source',
                        'label'        => __('Body'),
                    ],
                ],
            ],
            'image' => [
                'label'    => __('Image'),
                'rowTitle' => '{caption}',
                'children' => [
                    'photo' => [
                        'type'     => 'image',
                        'maxFiles' => 1,
                    ],
                    'caption' => [
                        'type' => 'text',
                    ],
                ],
            ],
        ],
    ],
];
```

This creates one field with a heading, a hero image, file downloads and two types of content blocks: **Text** and **Image**.

Editors can add, duplicate, reorder and remove blocks directly in Page Edit. Each row has a stable identity and retains its own type.

### Render the content

Access Flex properties directly in a ProcessWire template:

```php
<?php namespace ProcessWire; ?>

<article>
    <h1><?= $page->content->heading ?></h1>

    <?php if($image = $page->content->hero): ?>
        <img
            src="<?= $image->size(1200, 700)->url ?>"
            alt=""
        >
    <?php endif; ?>

    <?php foreach($page->content->blocks as $block): ?>

        <?php if($block->rowType() === 'text'): ?>
            <section>
                <h2><?= $block->heading ?></h2>
                <?= $block->body ?>
            </section>

        <?php elseif($block->rowType() === 'image'): ?>
            <?php if($image = $block->photo): ?>
                <figure>
                    <img src="<?= $image->url ?>" alt="">

                    <?php if($block->caption): ?>
                        <figcaption>
                            <?= $block->caption ?>
                        </figcaption>
                    <?php endif; ?>
                </figure>
            <?php endif; ?>
        <?php endif; ?>

    <?php endforeach; ?>

    <?php if(count($page->content->downloads)): ?>
        <h2>Downloads</h2>
        <ul>
            <?php foreach($page->content->downloads as $file): ?>
                <li>
                    <a href="<?= $file->url ?>">
                        <?= $file->name ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
```

Flex follows the Page's output-formatting state. Ordinary text is entity-encoded by default when output formatting is enabled, while TinyMCE content retains its HTML markup. Explicit or inherited [native Textformatters](API.md#textformatters) control text output without additional Flex encoding.

### Query with ProcessWire selectors

Flex properties are searchable through ProcessWire's selector API:

```php
// Match a regular property
$pages->find('content.heading*=Welcome');

// Match a property inside a specific row type
$pages->find('content.blocks.text.heading*=Introduction');

// Find pages containing image blocks
$pages->find('content.blocks._type=image');

// Find pages with at least three blocks
$pages->find('content.blocks.count>=3');
```

You can also work directly with specific row types:

```php
// Existing image rows, in their current order
$images = $page->content->blocks->rowsOfType('image');

// Number of text rows
$count = $page->content->blocks->countOfType('text');
```

See [API.md](API.md#selectors) for supported operators, same-row conditions and nested selectors.

## Nested rows

A `rows` collection can contain other `rows` collections. Each collection defines its own named row types and child properties.

For example, sections can contain content blocks, and individual blocks can contain further collections.

There is no fixed two-level nesting limit. The effective depth depends on database JSON limits and configurable resource budgets.

See the [nested rows example](examples/nested.php) and [API reference](API.md#nested-repeatables-and-resource-limits).

## More examples

- [Structured content](examples/structured.php) — typed rows, files and images
- [Nested rows](examples/nested.php) — multiple levels of row collections
- [Contact](examples/contact.php) — text, choices and ordinary fieldsets
- [Multilingual fields](examples/languages.php) — language-aware properties
- [Native Inputfield objects](examples/typed-objects.php) — programmatic row definitions
- [Native Repeater](examples/repeater.php) — Flex within ProcessWire Repeater items
- [Inputfield configuration reuse](examples/inherit-field.php) — inherit settings from existing fields

For the complete definition syntax, value types, settings and runtime methods, see **[API.md](API.md)**.

## Current limitations

FieldtypeFlex is a pre-release. Some ProcessWire features and integrations are not supported:

- SQLite and PostgreSQL databases.
- Native ProcessWire page-version save/restore.
- Selector traversal through Page references.
- Certain media options, including overwrite mode and custom metadata templates. TinyMCE drag-and-drop uploads require a regular ProcessWire Image field as their destination.

See [API.md](API.md) for the complete supported contract.

## Development

The repository includes a disposable DDEV environment for development and integration testing.

```sh
bash scripts/setup.sh
ddev flex-test
ddev composer lint
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/development.md](docs/development.md) for development instructions.

## License

FieldtypeFlex is released under the [MIT License](LICENSE).
