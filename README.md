<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/wordmark-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset="docs/images/wordmark-light.svg">
  <img alt="FieldtypeFlex" src="docs/images/wordmark-light.svg" height="48">
</picture>

**Schema-defined structured content in a single ProcessWire field.**

[![Version](https://img.shields.io/github/v/release/phlppschrr/FieldtypeFlex?include_prereleases&label=version&color=eb1d61)](https://github.com/phlppschrr/FieldtypeFlex/releases)
![ProcessWire 3.0.259+](https://img.shields.io/badge/ProcessWire-3.0.259%2B-1f2328)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Define objects, typed repeatable blocks, and editable tables in PHP or JSON — version-controlled in Git, rendered with native ProcessWire Inputfields, and stored without extra Repeater pages or separate fields. Read values through the familiar ProcessWire API and query them with standard selectors.

https://github.com/user-attachments/assets/eb40e7a9-d51c-47b3-b798-db6acb97651a

[View a static screenshot](docs/images/editor.jpg)

---

## Building blocks

Combine four composable structures inside a single Flex field:

| Structure | What it gives you |
| --- | --- |
| **Native inputs** | Regular ProcessWire Inputfields (TinyMCE, Images, Files, Page references, Multilingual, conditional `showIf`/`requiredIf`) with familiar settings. |
| **Objects** | Fixed, nested property groups that keep related values together without creating separate ProcessWire fields. |
| **Rows** | Repeatable, typed, and nestable content blocks with dynamic titles — no extra Repeater pages required. |
| **Tables** | Fixed columns with inline editing, optional editable headings and row labels, column sorting, and expandable detail rows for rich text, images, files, and page trees. |

## Features

### Schema as code & developer experience
- **Git-friendly definitions.** Author schemas in PHP, JSON, or native `InputfieldWrapper` objects — with reusable parts, page-aware logic, and `inheritField` to reuse existing field settings.
- **IDE autocompletion.** Generate ready-to-use PHPDoc class stubs directly from the field settings (or programmatically via `getPhpDoc()`).
- **Safe schema evolution.** Unknown properties survive normal saves, and `renamedFrom` plus built-in maintenance tools handle renames and cleanup without data loss.

### Native editor experience
- **Feels like core ProcessWire.** Uses native ProcessWire Inputfields, editor layouts, and scoped dependencies (`parent.`, `root.`, `page.`, `forpage.`). Also works inside native ProcessWire Repeaters.
- **Copy, paste, and bulk actions.** Copy and paste single or multiple rows — even across different ProcessWire installations, with automatic resolution of Page references, language values, and media. Select multiple rows to copy, hide, or mark them for removal in one step.
- **Smooth row & table workflows.** Add, duplicate, hide, reorder via drag-and-drop, and sort tables manually from column headers or automatically on save (`sortOnSave`).

### Media, languages & querying
- **First-class files and images.** Returns ProcessWire-compatible `Pagefile` and `Pageimage` objects with resizing and per-file custom metadata fields.
- **Multilingual & Language Alternates.** Supports ProcessWire's native multilingual inputs as well as sibling Language Alternate fields (such as `image` and `image_de`) with automatic fallback.
- **Full ProcessWire selectors.** Find pages by properties inside Objects, Rows, and Tables — including nested values, block types, and row counts.

---

## Quick start

Let's build a `content` field with an introduction and two repeatable block types: **Text** and **Gallery**.

### 1. Define the schema

Create a Flex field named `content`, add it to your template (with `InputfieldTinyMCE` installed), and save this definition as `site/templates/flex-fields/content.php`:

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
                // Dynamic row header with fallback value
                'rowTitle' => '#{@index} {heading|"No heading"}',
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

### 2. Render in your template

Read Flex values through the standard ProcessWire API (with output formatting enabled):

```php
echo $page->content->intro;

foreach($page->content->blocks as $block) {
    if($block->rowType() === 'text') {
        echo "<h2>{$block->heading}</h2>";
        echo $block->body;
    } else if($block->rowType() === 'gallery') {
        echo "<div class='gallery gallery--{$block->layout}'>";

        foreach($block->images as $image) {
            $thumb = $image->size(800, 600);
            echo "<img src='{$thumb->url}' alt='{$image->description}'>";
        }

        echo "</div>";
    }
}
```

### 3. Query with selectors

Search across structured properties using standard ProcessWire selectors:

```php
// Find pages with a Text block whose heading matches "Architecture"
$matches = $pages->find('content.blocks.text.heading*=Architecture');

// Find pages containing more than one Gallery block
$withGalleries = $pages->find('content.blocks.gallery.count>1');
```

> **Tip:** Open the Flex field's settings in ProcessWire Admin to copy generated PHPDoc stubs for full IDE autocompletion.

---

## Installation

**Requirements:** ProcessWire 3.0.259+, PHP 8.1+, MySQL 8.0+ or MariaDB 10.11+ (InnoDB).

1. Copy the module to `/site/modules/FieldtypeFlex/`.
2. In ProcessWire Admin, go to **Modules → Refresh** and install **FieldtypeFlex**.
3. Create a field of type **Flex** and add it to a template.

Bundled German, French, and Spanish admin translations can be installed from the module's Languages section.

---

## Examples & documentation

- **[Configuration examples](examples/README.md)** — ready-to-use PHP, JSON, and native Inputfield definitions covering Objects, nested Rows, Tables, conditional inputs, and custom media metadata.
- **[API reference](API.md)** — full reference for settings, value types, selectors, clipboard behavior, maintenance tools, and compatibility details.

## Known limitations

FieldtypeFlex is currently in beta — feature-complete for 1.0, with a focus on stability and real-world testing. The following ProcessWire features are not supported:

- SQLite and PostgreSQL databases.
- Native ProcessWire page-version save/restore and the **Pages Export/Import** module.
- Automatic backlink discovery (`$page->links()`) and direct Fieldtype conversion to or from other Fieldtypes.
- Compound or table-backed fields (`Repeater`, `RepeaterMatrix`, `PageTable`, `Password`, or nested `Flex`) as Flex properties — though Flex fields work inside native Repeaters.
- `CKEditor` (use `TinyMCE` instead).
- Page-reference selector traversal from typed Rows, across multiple hops, or into unsupported target fields.
- Media overwrite mode, native custom-field templates for files/images (use Flex `customFields` instead), and TinyMCE drag-and-drop uploads (which require a regular ProcessWire Image field as their target).

See [API.md](API.md) for full compatibility details.

## Feedback & contributing

Bug reports, real-world feedback, and contributions are welcome. Feel free to [open an issue](https://github.com/phlppschrr/FieldtypeFlex/issues) or see [CONTRIBUTING.md](CONTRIBUTING.md) if you'd like to help.

## License

Released under the [MIT License](LICENSE).
