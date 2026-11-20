<?php
/**
 * Build patches for the prefixed PDF library (WordPress.org review 1, amendment A3), applied by bin/vendor-pdf.sh to the
 * Strauss-prefixed copy before the direct-request guard is added. Not shipped.
 *
 * Each entry: file (relative to vendor-pdf/), note (the change notice: LGPL-2.1 section 2(b), LGPL-3.0 via GPL-3.0
 * section 5(a)), date (of the change; default 2026-11-18, review 1), and edits, applied in order. An edit is either
 *   array( 'find' => <exact text>, 'replace' => <text> )                                  find occurs exactly once; or
 *   array( 'from' => <text>, 'to' => <text>, 'sha256' => <hex>, 'replace' => <text> )     from and to each occur exactly
 *     once, to after from, and the span from the start of from to the end of to has that SHA-256.
 * Anything else fails the build: a patch that no longer applies to the pinned upstream code is never skipped.
 * The builder (tools/pdf/rewrite.php notices) then puts "// Modified by Integrity Web Studios, DATE: <note>" after the
 * file's opening tag and lists every note in vendor-pdf/README.txt.
 */

$page_script_find = <<<'PHP'
     * @param callable|string $callback The callback function or PHP script to process on every page
     */
    public function page_script($callback): void
    {
        if (is_string($callback)) {
            $this->processPageScript(function (
                int $PAGE_NUM,
                int $PAGE_COUNT,
                self $pdf,
                FontMetrics $fontMetrics
            ) use ($callback) {
                eval($callback);
            });
            return;
        }

        $this->processPageScript($callback);
    }
PHP;

$page_script_replace = <<<'PHP'
     * @param callable|string $callback The callback function to process on every page (IWS Church Manager build: a string is refused)
     */
    public function page_script($callback): void
    {
        // IWS Church Manager build patch: a PHP source string is never run, and a string is not taken as a callable name.
        if (is_string($callback)) {
            throw new \InvalidArgumentException("String page scripts are not supported in this build; pass a callable.");
        }

        $this->processPageScript($callback);
    }
PHP;

$restore_find = <<<'PHP'
    function restoreSerializedObject($obj)
    {
        $obj_id = $this->openObject();
        $this->objects[$obj_id] = unserialize($obj);
        $this->closeObject();
PHP;

$restore_replace = <<<'PHP'
    function restoreSerializedObject($obj)
    {
        // IWS Church Manager build patch: no class is ever instantiated from the stored form, and only an array is accepted.
        $data = unserialize($obj, ['allowed_classes' => false]);
        if (!is_array($data)) {
            throw new \InvalidArgumentException("restoreSerializedObject() expects a serialized PDF object array.");
        }
        $obj_id = $this->openObject();
        $this->objects[$obj_id] = $data;
        $this->closeObject();
PHP;

$restore_note = 'restoreSerializedObject(): unserialize() with allowed_classes false, and anything but an array is refused (InvalidArgumentException).';

return array(
	array(
		'file'  => 'dompdf/dompdf/src/Adapter/CPDF.php',
		'note'  => 'page_script(): the branch that ran a PHP source string is removed; a string page script is refused (InvalidArgumentException); callables are unchanged.',
		'edits' => array( array( 'find' => $page_script_find, 'replace' => $page_script_replace ) ),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Adapter/PDFLib.php',
		'note'  => 'page_script(): the branch that ran a PHP source string is removed; a string page script is refused (InvalidArgumentException); callables are unchanged.',
		'edits' => array( array( 'find' => $page_script_find, 'replace' => $page_script_replace ) ),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Helpers.php',
		'note'  => 'getFileContent(): local files only (a path, or file://); the cURL and URL-wrapper remote-fetch branches and phar:// are removed, and any other protocol returns no content.',
		'edits' => array(
			array(
				'from'    => "    /**\n     * Gets the content of the file at the specified path using one of\n",
				'to'      => "        return [\$content, \$headers];\n    }\n",
				'sha256'  => '3b2f781d6eff86db1c0716c3c802154e0bc2547de16e49195f41c2dfbed0016e',
				'replace' => <<<'PHP'
    /**
     * Gets the content of a local file (IWS Church Manager build: a path or file:// only; there is no remote fetch,
     * and any other protocol returns no content).
     *
     * @param string $uri
     * @param resource $context
     * @param int $offset
     * @param int $maxlen
     * @return string[]
     */
    public static function getFileContent($uri, $context = null, $offset = 0, $maxlen = null)
    {
        $content = null;
        $headers = null;
        [$protocol] = Helpers::explode_url($uri);
        if (!in_array(strtolower($protocol), ["", "file://"], true)) {
            return [$content, $headers];
        }

        set_error_handler([self::class, 'record_warnings']);

        try {
            if (isset($maxlen)) {
                $result = file_get_contents($uri, false, $context, $offset, $maxlen);
            } else {
                $result = file_get_contents($uri, false, $context, $offset);
            }
            if ($result !== false) {
                $content = $result;
            }
        } finally {
            restore_error_handler();
        }

        return [$content, $headers];
    }

PHP
			),
		),
	),
	array(
		'file'  => 'dompdf/dompdf/lib/Cpdf.php',
		'note'  => $restore_note,
		'edits' => array( array( 'find' => $restore_find, 'replace' => $restore_replace ) ),
	),
	array(
		'file'  => 'dompdf/php-svg-lib/src/Svg/Surface/CPdf.php',
		'note'  => $restore_note,
		'edits' => array( array( 'find' => $restore_find, 'replace' => $restore_replace ) ),
	),
	array(
		'file'  => 'dompdf/php-font-lib/src/FontLib/Table/Type/glyf.php',
		'note'  => 'toHTML(): the font-inspection debug page (HTML with inline script and an inline event handler) is removed; it returns an empty string.',
		'edits' => array(
			array(
				'from'    => "  public function toHTML(\$n = 500) {\n",
				'to'      => "    return \$s;\n  }\n",
				'sha256'  => '5d3b576dc1a39cb44677bcd4475c99484cf2eb3f77e0f4ae386f18e5e00192ed',
				'replace' => <<<'PHP'
  public function toHTML($n = 500) {
    // IWS Church Manager build patch: the font-inspection debug page is not shipped.
    return "";
  }

PHP
			),
		),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Css/Stylesheet.php',
		'note'  => '__construct(): the initial base path is this file and is no longer read from the request (SCRIPT_FILENAME); Dompdf sets the document base before any stylesheet is read.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
        $script = __FILE__;
        if (isset($_SERVER["SCRIPT_FILENAME"])) {
            $script = $_SERVER["SCRIPT_FILENAME"];
        }

PHP
				,
				'replace' => <<<'PHP'
        // IWS Church Manager build patch: not read from the request; Dompdf sets the document base before any stylesheet is read.
        $script = __FILE__;

PHP
				,
			),
		),
	),
	// WordPress.org review 3: this private build never prints dompdf diagnostics (the globals are renamed by rewrite.php).
	array(
		'file'  => 'dompdf/dompdf/src/Helpers.php',
		'date'  => '2026-11-20',
		'note'  => 'dompdf_debug() prints nothing (its debug echo is removed); record_warnings() only records a warning, never echoes it.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
        global $_DOMPDF_DEBUG_TYPES, $_dompdf_show_warnings, $_dompdf_debug;
        if (isset($_DOMPDF_DEBUG_TYPES[$type]) && ($_dompdf_show_warnings || $_dompdf_debug)) {
            $arr = debug_backtrace();

            echo basename($arr[0]["file"]) . " (" . $arr[0]["line"] . "): " . $arr[1]["function"] . ": ";
            Helpers::pre_r($msg);
        }
PHP
				,
				'replace' => <<<'PHP'
        // IWS Church Manager build patch: debug output is never printed.
PHP
				,
			),
			array(
				'find'    => <<<'PHP'
        global $_dompdf_warnings;
        global $_dompdf_show_warnings;

        if ($_dompdf_show_warnings) {
            echo $errstr . "\n";
        }

        $_dompdf_warnings[] = $errstr;
PHP
				,
				'replace' => <<<'PHP'
        // IWS Church Manager build patch: a warning is recorded, never printed.
        global $_dompdf_warnings;

        $_dompdf_warnings[] = $errstr;
PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Dompdf.php',
		'date'  => '2026-11-20',
		'note'  => 'render(): the block that echoed the collected warnings (show-warnings switch) is removed.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
        global $_dompdf_warnings, $_dompdf_show_warnings;
        if ($_dompdf_show_warnings && isset($_dompdf_warnings)) {
            echo '<b>Dompdf Warnings</b><br><pre>';
            foreach ($_dompdf_warnings as $msg) {
                echo $msg . "\n";
            }

            if ($canvas instanceof CPDF) {
                echo $canvas->get_cpdf()->messages;
            }
            echo '</pre>';
            flush();
        }

PHP
				,
				'replace' => <<<'PHP'
        // IWS Church Manager build patch: collected warnings are never echoed.

PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Renderer.php',
		'date'  => '2026-11-20',
		'note'  => 'render(): the debug dump that echoed each frame is removed.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
        global $_dompdf_debug;

        $this->_check_callbacks("begin_frame", $frame);

        if ($_dompdf_debug) {
            echo $frame;
            flush();
        }

PHP
				,
				'replace' => <<<'PHP'
        // IWS Church Manager build patch: the frame debug dump is removed.
        $this->_check_callbacks("begin_frame", $frame);

PHP
				,
			),
		),
	),
	// WordPress.org review 3, round 3b: the remaining diagnostic output helpers. (rewrite.php then removes the debug-switch
	// branches and fails the build if any diagnostic output or generic debug switch is left.)
	array(
		'file'  => 'dompdf/dompdf/src/Helpers.php',
		'date'  => '2026-11-20',
		'note'  => 'pre_r(): the dump helper is a no-op that prints nothing and returns an empty string when asked to return.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
    /**
     * print_r wrapper for html/cli output
     *
     * Wraps print_r() output in < pre > tags if the current sapi is not 'cli'.
     * Returns the output string instead of displaying it if $return is true.
     *
     * @param mixed $mixed variable or expression to display
     * @param bool $return
     *
     * @return string|null
     */
    public static function pre_r($mixed, $return = false)
    {
        if ($return) {
            return "<pre>" . print_r($mixed, true) . "</pre>";
        }

        if (php_sapi_name() !== "cli") {
            echo "<pre>";
        }

        print_r($mixed);

        if (php_sapi_name() !== "cli") {
            echo "</pre>";
        } else {
            echo "\n";
        }

        flush();

        return null;
    }
PHP
				,
				'replace' => <<<'PHP'
    /**
     * Diagnostic dump helper (IWS Church Manager build: it prints nothing).
     *
     * @param mixed $mixed ignored
     * @param bool $return when true an empty string is returned
     *
     * @return string|null
     */
    public static function pre_r($mixed, $return = false)
    {
        return $return ? "" : null;
    }
PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Css/Style.php',
		'date'  => '2026-11-20',
		'note'  => 'debug_print() prints nothing; __toString() returns a JSON dump of the properties instead of a print-formatted dump.',
		'edits' => array(
			array(
				'find'    => "     * This dumps the entire property array into a string via print_r.  Useful\n",
				'replace' => "     * This dumps the entire property array into a JSON string.  Useful\n",
			),
			array(
				'find'    => <<<'PHP'
        return print_r(array_merge(["parent_font_size" => $parent_font_size],
            $this->_props), true);
PHP
				,
				'replace' => <<<'PHP'
        return (string) json_encode(array_merge(["parent_font_size" => $parent_font_size],
            $this->_props), JSON_PARTIAL_OUTPUT_ON_ERROR);
PHP
				,
			),
			array(
				'from'    => "    public function debug_print(): void\n",
				'to'      => "        print \"    ]\\n\";\n    }\n",
				'sha256'  => '43dff09050651dfca1ce581950672793bdf8d4b02cc0c6e1e23e6e56b5ca7f10',
				'replace' => <<<'PHP'
    public function debug_print(): void
    {
        // IWS Church Manager build patch: the style debug dump is not shipped; this prints nothing.
    }

PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/dompdf/src/Renderer/AbstractRenderer.php',
		'date'  => '2026-11-20',
		'note'  => '_background_image(): the "Unknown repeat" message that was printed is removed (the unknown value is ignored as before).',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
            } else {
                print 'Unknown repeat!';
            }
PHP
				,
				'replace' => <<<'PHP'
            }
PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/php-font-lib/src/FontLib/Font.php',
		'date'  => '2026-11-20',
		'note'  => 'd(): the debug line printer prints nothing.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
  static function d($str) {
    if (!self::$debug) {
      return;
    }
    echo "$str\n";
  }
PHP
				,
				'replace' => <<<'PHP'
  static function d($str) {
    // IWS Church Manager build patch: debug lines are never printed.
  }
PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/php-font-lib/src/FontLib/BinaryStream.php',
		'date'  => '2026-11-20',
		'note'  => 'backtrace(): the debug dump is removed; it does nothing.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
  static function backtrace() {
    var_dump(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
  }
PHP
				,
				'replace' => <<<'PHP'
  static function backtrace() {
    // IWS Church Manager build patch: the debug dump is not shipped.
  }
PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/php-font-lib/src/FontLib/Table/Table.php',
		'date'  => '2026-11-20',
		'note'  => 'toHTML(): the debug dump is removed; it returns an empty string.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
  public function toHTML() {
    return "<pre>" . var_export($this->data, true) . "</pre>";
  }
PHP
				,
				'replace' => <<<'PHP'
  public function toHTML() {
    // IWS Church Manager build patch: the debug dump is not shipped.
    return "";
  }
PHP
				,
			),
		),
	),
	array(
		'file'  => 'dompdf/php-font-lib/src/FontLib/EOT/File.php',
		'date'  => '2026-11-20',
		'note'  => 'a commented-out block of debug dump calls is removed (no code change).',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
      /*
      var_dump("$mtx_version $mtx_copy_limit $mtx_offset_1 $mtx_offset_2");

      $pos = $this->pos();
      $size = $mtx_offset_1 - $pos;
      var_dump("pos: $pos");
      var_dump("size: $size");*/
PHP
				,
				'replace' => <<<'PHP'
PHP
				,
			),
		),
	),
	array(
		'file'  => 'masterminds/html5/src/HTML5/Parser/StringInputStream.php',
		'date'  => '2026-11-20',
		'note'  => '__construct(): the debug format argument no longer writes the input to standard output; the argument is accepted and ignored.',
		'edits' => array(
			array(
				'find'    => <<<'PHP'
     * @param string $debug    A fprintf format to use to echo the data on stdout.
     */
    public function __construct($data, $encoding = 'UTF-8', $debug = '')
    {
        $data = UTF8Utils::convertToUTF8($data, $encoding);
        if ($debug) {
            fprintf(STDOUT, $debug, $data, strlen($data));
        }
PHP
				,
				'replace' => <<<'PHP'
     * @param string $debug    Ignored (IWS Church Manager build: nothing is written to standard output).
     */
    public function __construct($data, $encoding = 'UTF-8', $debug = '')
    {
        $data = UTF8Utils::convertToUTF8($data, $encoding);
PHP
				,
			),
		),
	),
	array(
		'file'  => 'masterminds/html5/src/HTML5/Parser/FileInputStream.php',
		'date'  => '2026-11-20',
		'note'  => '__construct(): the debug argument is documented as ignored (see StringInputStream).',
		'edits' => array(
			array(
				'find'    => "     * @param string \$debug    A fprintf format to use to echo the data on stdout.\n",
				'replace' => "     * @param string \$debug    Ignored (IWS Church Manager build: nothing is written to standard output).\n",
			),
		),
	),
);
