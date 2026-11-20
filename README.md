# IWS Church Manager: build tools

Public build tools for the [IWS Church Manager](https://integritywebstudios.com/church-manager/) WordPress plugin, by Integrity Web Studios.

Every file in the plugin is readable source; nothing is minified or compiled. Two folders inside the plugin are produced by a script. They are:

| Plugin folder | What it is | Built by |
|---|---|---|
| `vendor-pdf/` | Dompdf 3.1.6 and its dependencies (php-font-lib, php-svg-lib, html5-php, php-css-parser), prefixed under `IWSCM\Vendor\Pdf\` so they never clash with another plugin's copy | `bin/vendor-pdf.sh` with the inputs in `tools/pdf/` |
| `vendor-iws/` | IWS Common, Integrity Web Studios' own GPL code, prefixed under `IWSCM\Vendor\IWS\Common` | `bin/vendor-common.sh` (copy and rename namespaces only; the shipped files are the full source) |

## Rebuilding vendor-pdf

Requirements: bash, PHP 7.4 or later (CLI), curl, unzip, shasum.

```
bin/vendor-pdf.sh --out /tmp/vendor-pdf --manifest /tmp/vendor-pdf.manifest
```

The script:

1. Downloads the five upstream release archives from GitHub and Strauss (the prefixing tool) and stops unless each matches the SHA-256 pinned in `tools/pdf/archives.sha256` and `tools/pdf/strauss.sha256`.
2. Runs Strauss with `tools/pdf/strauss.json` and the exact versions in `tools/pdf/composer.json` / `composer.lock`.
3. Keeps only the files the plugin needs, applies the dated patches in `tools/pdf/patches.php` (each one is located by exact text and SHA-256, or the build stops), and runs `tools/pdf/rewrite.php`, which removes heredoc syntax, renames globals and removes debug output.
4. Adds a direct-request guard and a dated notice of modification to every PHP file (LGPL-2.1 section 2(b)), writes `README.txt`, and writes a SHA-256 manifest of every file.

Compare the output with the plugin's `vendor-pdf/` folder, or the manifest with `bin/vendor-pdf.manifest` here, file by file.

## Licence

These tools are GPL-2.0-or-later (see `LICENSE`). The bundled libraries keep their own licences: Dompdf and php-font-lib LGPL-2.1, php-svg-lib LGPL-3.0 (with the GNU GPL 3.0 text in `tools/pdf/gpl-3.0.txt`), html5-php and php-css-parser MIT, DejaVu fonts under their own licence (`tools/pdf/dejavu-fonts.LICENSE`).
