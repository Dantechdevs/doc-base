#!/usr/bin/env php
<?php // vim: ts=4 sw=4 et tw=78 fdm=marker

/*
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2023 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt.                                |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net, so we can mail you a copy immediately.              |
  +----------------------------------------------------------------------+
  | Authors:    Dave Barr <dave@php.net>                                 |
  |             Hannes Magnusson <bjori@php.net>                         |
  |             Gwynne Raskind <gwynne@php.net>                          |
  +----------------------------------------------------------------------+
*/

error_reporting(-1);
$cvs_id = '$Id$';

echo "configure.php: $cvs_id\n";

const RNG_SCHEMA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'docbook' . DIRECTORY_SEPARATOR . 'docbook-v5.2-os' . DIRECTORY_SEPARATOR . 'rng' . DIRECTORY_SEPARATOR;
const RNG_SCHEMA_FILE = RNG_SCHEMA_DIR . 'docbook.rng';
const RNG_SCHEMA_XINCLUDE_FILE = RNG_SCHEMA_DIR . 'docbookxi.rng';

function usage() // {{{
{
    global $acd;

    echo <<<HELPCHUNK
configure.php configures this package to adapt to many kinds of systems, and PhD
builds too.

Usage: ./configure [OPTION]...

Defaults for the options are specified in brackets.

Configuration:
  -h, --help                     Display this help and exit
  -V, --version                  Display version information and exit
  -q, --quiet, --silent          Do not print `checking...' messages
      --srcdir=DIR               Find the sources in DIR [configure dir or `.']
      --basedir                  Doc-base directory
                                 [{$acd['BASEDIR']}]
      --rootdir                  Root directory of SVN Doc checkouts
                                 [{$acd['ROOTDIR']}]

Package-specific:
  --enable-force-dom-save        Force .manual.xml to be saved in a full build
                                 even if it fails validation [{$acd['FORCE_DOM_SAVE']}]
  --enable-chm                   Enable Windows HTML Help Edition pages [{$acd['CHMENABLED']}]
  --enable-xml-details           Enable detailed XML error messages [{$acd['DETAILED_ERRORMSG']}]
  --disable-segfault-error       LIBXML may segfault with broken XML, use this
                                 if it does [{$acd['SEGFAULT_ERROR']}]
  --disable-version-files        Do not merge the extension specific
                                 version.xml files
  --disable-sources-file         Do not generate sources.xml file
  --disable-history-file         Do not copy file modification history file
  --disable-libxml-check         Disable the libxml 2.7.4+ requirement check
  --with-php=PATH                Path to php CLI executable [detect]
  --with-lang=LANG               Language to build [{$acd['LANG']}]
  --with-partial=my-xml-id       Root ID to build (e.g. <book xml:id="MY-ID">) [{$acd['PARTIAL']}]
  --disable-broken-file-listing  Do not ignore translated files in
                                 broken-files.txt
  --disable-xpointer-reporting   Do not show XInclude/XPointer failures. Only effective
                                 on translations
  --redirect-stderr-to-stdout    Redirect STDERR to STDOUT. Use STDOUT as the
                                 standard output for XML errors [{$acd['STDERR_TO_STDOUT']}]
  --output=FILENAME              Save to given file (i.e. not .manual.xml)
                                 [{$acd['OUTPUT_FILENAME']}]
  --generate=FILENAME            Create an XML only for provided file

HELPCHUNK;
} // }}}

function errbox($msg) {
    $len = strlen($msg)+4;
    $line = "+" . str_repeat("-", $len) . "+";

    echo $line, "\n";
    echo "|  ", $msg, "  |", "\n";
    echo $line, "\n\n";
}
function errors_are_bad($status) {
    echo "\nEyh man. No worries. Happ shittens. Try again after fixing the errors above.\n";
    exit($status);
}

function is_windows() {
    return PHP_OS === 'WINNT';
}

function checking($for) // {{{
{
    global $ac;

    if ($ac['quiet'] != 'yes') {
        echo "Checking {$for}... ";
        flush();
    }
} // }}}

function checkerror($msg) // {{{
{
    global $ac;

    if ($ac['quiet'] != 'yes') {
        echo "\n";
    }
    echo "error: {$msg}\n";
    exit(1);
} // }}}

function checkvalue($v) // {{{
{
    global $ac;

    if ($ac['quiet'] != 'yes') {
        echo "{$v}\n";
    }
} // }}}

function abspath($path) // {{{
{
    // realpath() doesn't return empty for empty on Windows
    if ($path == '') {
        return '';
    }
    return str_replace('\\', '/', function_exists('realpath') ? realpath($path) : $path);
} // }}}

function quietechorun($e) // {{{
{
    // enclose in "" on Windows for PHP < 5.3
    if (is_windows() && phpversion() < '5.3') {
        $e = '"'.$e.'"';
    }

    passthru($e);
} // }}}

function find_file($file_array) // {{{
{
    $paths = explode(PATH_SEPARATOR, getenv('PATH'));

    if (is_array($paths)) {
        foreach ($paths as $path) {
            foreach ($file_array as $name) {
                if (file_exists("{$path}/{$name}") && is_file("{$path}/{$name}")) {
                    return "{$path}/{$name}";
                }
            }
        }
    }

    return '';
} // }}}

// Recursive glob() with a callback function {{{
function globbetyglob($globber, $userfunc)
{
    foreach (glob("$globber/*") as $file) {
        if (is_dir($file)) {
            globbetyglob($file, $userfunc);
        } else {
            call_user_func($userfunc, $file);
        }
    }
} // }}}

function find_dot_in($filename) // {{{
{
    if (substr($filename, -3) == '.in') {
        $GLOBALS['infiles'][] = $filename;
    }
} // }}}

function generate_output_file($in, $out, $ac) // {{{
{
    $data = file_get_contents($in);

    if ($data === false) {
        return false;
    }
    foreach ($ac as $k => $v) {
        $data = str_replace("@$k@", $v, $data);
    }

    return file_put_contents($out, $data);
} // }}}

function make_scripts_executable($filename) // {{{
{
    if (substr($filename, -3) == '.sh') {
        chmod($filename, 0755);
    }
} // }}}

// Loop through and print out all XML validation errors {{{
function print_xml_errors($details = true) {
    global $ac;
    $errors = libxml_get_errors();
    $output = ( $ac['STDERR_TO_STDOUT'] == 'yes' ) ? STDOUT : STDERR;
    if ($errors && count($errors) > 0) {
        foreach($errors as $err) {
                if ($ac['LANG'] != 'en' &&                 // translations
                    $ac['XPOINTER_REPORTING'] != 'yes' &&  // can disable
                    strncmp($err->message, 'XPointer evaluation failed:', 27) == 0) {
                    continue;
                }
                $errmsg = wordwrap(" " . trim($err->message), 80, "\n ");
                if ($details && $err->file) {
                    $file = file(urldecode($err->file)); // libxml appears to urlencode() its errors strings
                    if (isset($file[$err->line])) {
                        $line = rtrim($file[$err->line - 1]);
                        $padding = str_repeat("-", $err->column) . "^";
                        fprintf($output, "\nERROR (%s:%s:%s)\n%s\n%s\n%s\n", $err->file, $err->line, $err->column, $line, $padding, $errmsg);
                    } else {
                        fprintf($output, "\nERROR (%s:unknown)\n%s\n", $err->file, $errmsg);
                    }
                } else {
                    fprintf($output, "%s\n", $errmsg);
                }
                // Error too severe, stopping
                if ($err->level === LIBXML_ERR_FATAL) {
                    fprintf($output, "\n\nPrevious errors too severe. Stopping here.\n\n");
                    break;
                }
        }
    }
    libxml_clear_errors();
} // }}}

function find_xml_files($path) // {{{
{
    $path = rtrim($path, '/');
    $prefix_len = strlen($path . '/');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($files as $fileinfo) {
        if ($fileinfo->getExtension() === 'xml') {
            yield substr($fileinfo->getPathname(), $prefix_len);
        }
    }
} // }}}

function generate_sources_file() // {{{
{
    global $ac;
    $source_map = array();
    echo 'Iterating over files for sources info... ';
    $en_dir = "{$ac['rootdir']}/{$ac['EN_DIR']}";
    $source_langs = array(
        array('base', $ac['srcdir'], array('manual.xml.in', 'funcindex.xml')),
        array('en', $en_dir, find_xml_files($en_dir)),
    );
    if ($ac['LANG'] !== 'en') {
        $lang_dir = "{$ac['rootdir']}/{$ac['LANGDIR']}";
        $source_langs[] = array($ac['LANG'], $lang_dir, find_xml_files($lang_dir));
    }
    foreach ($source_langs as list($source_lang, $source_dir, $source_files)) {
        foreach ($source_files as $source_path) {
            $source = file_get_contents("{$source_dir}/{$source_path}");
            if (preg_match_all('/ xml:id=(["\'])([^"]+)\1/', $source, $matches)) {
                foreach ($matches[2] as $xml_id) {
                    $source_map[$xml_id] = array(
                        'lang' => $source_lang,
                        'path' => $source_path,
                    );
                }
            }
        }
    }
    asort($source_map);
    echo "OK\n";
    echo 'Generating sources XML... ';
    $dom = new DOMDocument;
    $dom->formatOutput = true;
    $sources_elem = $dom->appendChild($dom->createElement("sources"));
    foreach ($source_map as $id => $source) {
        $el = $dom->createElement('item');
        $el->setAttribute('id', $id);
        $el->setAttribute('lang', $source["lang"]);
        $el->setAttribute('path', $source["path"]);
        $sources_elem->appendChild($el);
    }
    echo "OK\n";
    echo "Saving sources.xml file... ";
    if ($dom->save($ac['srcdir'] . '/sources.xml')) {
        echo "OK\n";
    } else {
        echo "FAIL!\n";
    }
} // }}}

function getFileModificationHistory(): array {
    global $ac;

    $lang_mod_file = (($ac['LANG'] !== 'en') ? ("{$ac['rootdir']}/{$ac['EN_DIR']}") : ("{$ac['rootdir']}/{$ac['LANGDIR']}")) . "/fileModHistory.php";
    $doc_base_mod_file = __DIR__ . "/fileModHistory.php";

    $history_file = null;
    if (file_exists($lang_mod_file)) {
        $history_file = include $lang_mod_file;
        if (is_array($history_file)) {
            echo 'Copying modification history file... ';
            $isFileCopied = copy($lang_mod_file, $doc_base_mod_file);
            echo $isFileCopied ? "done.\n" : "failed.\n";
        } else {
            echo "Corrupted modification history file found: $lang_mod_file \n";
        }
    } else {
        echo "Modification history file $lang_mod_file not found.\n";
    }

    if (!is_array($history_file)) {
        $history_file = [];
        echo "Creating empty modification history file...";
        file_put_contents($doc_base_mod_file, "<?php\n\nreturn [];\n");
        echo "done.\n";
    }

    return $history_file;
}

$srcdir  = dirname(__FILE__);
$workdir = $srcdir;
$basedir = $srcdir;
$rootdir = dirname($basedir);

/**
 * When checking out this repository on GitHub Actions, the workspace  directory is "/home/runner/work/doc-base/doc-base".
 *
 * To avoid applying dirname() here, we check if we are running on GitHub Actions.
 *
 * @see https://docs.github.com/en/free-pro-team@latest/actions/reference/environment-variables#default-environment-variables
 */
if (getenv('GITHUB_ACTIONS') !== 'true' && basename($rootdir) === 'doc-base') {
    $rootdir = dirname($rootdir);
}

// Settings {{{
$cygwin_php_bat = "{$srcdir}/../phpdoc-tools/php.bat";
$php_bin_names = array('php', 'php5', 'cli/php', 'php.exe', 'php5.exe', 'php-cli.exe', 'php-cgi.exe');
// }}}

// Reject old PHP installations {{{
if (phpversion() < 5) {
    echo "PHP 5 or above is required. Version detected: " . phpversion() . "\n";
    exit(100);
} else {
    echo "PHP version: " . phpversion() . "\n";
} // }}}

echo "\n";

$acd = array( // {{{
    'srcdir' => $srcdir,
    'basedir' => $basedir,
    'rootdir' => $rootdir,
    'workdir' => $workdir,
    'quiet' => 'no',
    'WORKDIR' => $srcdir,
    'SRCDIR' => $srcdir,
    'BASEDIR' => $basedir,
    'ROOTDIR' => $rootdir,
    'ONLYDIR' => "{$rootdir}/en",
    'PHP' => '',
    'CHMENABLED' => 'no',
    'CHMONLY_INCL_BEGIN' => '<!--',
    'CHMONLY_INCL_END' => '-->',
    'LANG' => 'en',
    'LANGDIR' => "{$rootdir}/en",
    'ENCODING' => 'utf-8',
    'FORCE_DOM_SAVE' => 'no',
    'PARTIAL' => 'no',
    'DETAILED_ERRORMSG' => 'no',
    'SEGFAULT_ERROR' => 'yes',
    'VERSION_FILES'  => 'yes',
    'SOURCES_FILE' => 'yes',
    'HISTORY_FILE' => 'yes',
    'LIBXML_CHECK' => 'yes',
    'USE_BROKEN_TRANSLATION_FILENAME' => 'yes',
    'OUTPUT_FILENAME' => $srcdir . '/.manual.xml',
    'GENERATE' => 'no',
    'STDERR_TO_STDOUT' => 'no',
    'INPUT_FILENAME'   => 'manual.xml',
    'TRANSLATION_ONLY_INCL_BEGIN' => '',
    'TRANSLATION_ONLY_INCL_END' => '',
    'XPOINTER_REPORTING' => 'yes',
); // }}}

$ac = $acd;

$srcdir_dependant_settings = array( 'LANGDIR' );
$overridden_settings = array();

foreach ($_SERVER['argv'] as $k => $opt) { // {{{
    $parts = explode('=', $opt, 2);
    if (strncmp($opt, '--enable-', 9) == 0) {
        $o = substr($parts[0], 9);
        $v = 'yes';
    } else if (strncmp($opt, '--disable-', 10) == 0 || strncmp($opt, '--without-', 10) == 0) {
        $o = substr($parts[0], 10);
        $v = 'no';
    } else if (strncmp($opt, '--with-', 7) == 0) {
        $o = substr($parts[0], 7);
        $v = isset($parts[1]) ? $parts[1] : 'yes';
    } else if (strncmp($opt, '--redirect-', 11) == 0) {
        $o = substr($parts[0], 11);
        $v = 'yes';
    } else if (strncmp($opt, '--', 2) == 0) {
        $o = substr($parts[0], 2);
        $v = isset($parts[1]) ? $parts[1] : 'yes';
    } else if ($opt[0] == '-') {
        $o = $opt[1];
        $v = strlen($opt) > 2 ? substr($opt, 2) : 'yes';
    } else {
        continue;
    }

    $overridden_settings[] = strtoupper($o);
    switch ($o) {
        case 'h':
        case 'help':
            usage();
            exit();

        case 'V':
        case 'version':
            // Version/revision is always printed out
            exit();

        case 'q':
        case 'quiet':
        case 'silent':
            $ac['quiet'] = $v;
            break;

        case 'srcdir':
            foreach ($srcdir_dependant_settings as $s) {
                if (!in_array($s, $overridden_settings)) {
                    $ac[$s] = $v . substr($ac[$s], strlen($ac['srcdir']));
                }
            }
            $ac['srcdir'] = $v;
            break;

        case 'force-dom-save':
            $ac['FORCE_DOM_SAVE'] = $v;
            break;

        case 'chm':
            $ac['CHMENABLED'] = $v;
            break;

        case 'php':
            $ac['PHP'] = $v;
            break;

        case 'lang':
            $ac['LANG'] = $v;
            break;

        case 'partial':
            if ($v == "yes") {
                if (isset($_SERVER['argv'][$k+1])) {
                    $val = $_SERVER['argv'][$k+1];
                    errbox("TYPO ALERT: Didn't you mean --{$o}={$val}?");
                } else {
                    errbox("TYPO ALERT: --partial without a chunk ID?");
                }
            }

            $ac['PARTIAL'] = $v;
            break;

        case 'xml-details':
            $ac['DETAILED_ERRORMSG'] = $v;
            break;

        case 'segfault-error':
            $ac['SEGFAULT_ERROR'] = $v;
            break;

        case 'version-files':
            $ac['VERSION_FILES'] = $v;
            break;

        case 'sources-file':
            $ac['SOURCES_FILE'] = $v;
            break;

        case 'history-file':
            $ac['SOURCES_FILE'] = $v;
            break;

        case 'libxml-check':
            $ac['LIBXML_CHECK'] = $v;
            break;

        case 'rootdir':
            $ac['rootdir'] = $v;
            break;

        case 'basedir':
            $ac['basedir'] = $v;
            break;

        case 'output':
            $ac['OUTPUT_FILENAME'] = $v;
            break;

        case 'generate':
            $ac['GENERATE'] = $v;
            break;

        case 'broken-file-listing':
            $ac['USE_BROKEN_TRANSLATION_FILENAME'] = $v;
            break;

        case 'stderr-to-stdout':
            $ac['STDERR_TO_STDOUT'] = $v;
            break;

        case 'xpointer-reporting':
            $ac['XPOINTER_REPORTING'] = $v;
            break;

        case '':
            break;

        default:
            echo "WARNING: Unknown option '{$o}'!\n";
            break;
    }
} // }}}

// Reject 'old' LibXML installations, due to LibXML feature #502960 {{{
if (version_compare(LIBXML_DOTTED_VERSION, '2.7.4', '<') && $ac['LIBXML_CHECK'] === 'yes') {
    echo "LibXML 2.7.4+ added a 'feature' to break things, typically namespace related, and unfortunately we must require it.\n";
    echo "For a few related details, see: http://www.mail-archive.com/debian-bugs-dist@lists.debian.org/msg777646.html\n";
    echo "Please recompile PHP with a LibXML version 2.7.4 or greater. Version detected: " . LIBXML_DOTTED_VERSION . "\n";
    echo "Or, pass in --disable-libxml-check if doing so feels safe.\n\n";
    #exit(100);
} // }}}

checking('for source directory');
if (!file_exists($ac['srcdir']) || !is_dir($ac['srcdir']) || !is_writable($ac['srcdir'])) {
    checkerror("Source directory doesn't exist or can't be written to.");
}
$ac['SRCDIR'] = $ac['srcdir'];
$ac['WORKDIR'] = $ac['srcdir'];
$ac['ROOTDIR'] = $ac['rootdir'];
$ac['BASEDIR'] = $ac['basedir'];
checkvalue($ac['srcdir']);

checking('for output filename');
checkvalue($ac['OUTPUT_FILENAME']);

checking('whether to include CHM');
$ac['CHMONLY_INCL_BEGIN'] = ($ac['CHMENABLED'] == 'yes' ? '' : '<!--');
$ac['CHMONLY_INCL_END'] = ($ac['CHMENABLED'] == 'yes' ? '' : '-->');
checkvalue($ac['CHMENABLED']);

checking("for PHP executable");
if ($ac['PHP'] == '' || $ac['PHP'] == 'no') {
    $ac['PHP'] = find_file($php_bin_names);
} else if (file_exists($cygwin_php_bat)) {
    $ac['PHP'] = $cygwin_php_bat;
}

if ($ac['PHP'] == '') {
    checkerror("Could not find a PHP executable. Use --with-php=/path/to/php.");
}
if (!file_exists($ac['PHP']) || !is_executable($ac['PHP'])) {
    checkerror("PHP executable is invalid - how are you running configure? " .
               "Use --with-php=/path/to/php.");
}
$ac['PHP'] = abspath($ac['PHP']);
checkvalue($ac['PHP']);

checking("for language to build");
if ($ac['LANG'] == '' /* || $ac['LANG'] == 'no' */) {
    checkerror("Using '--with-lang=' or '--without-lang' is just going to cause trouble.");
} else if ($ac['LANG'] == 'yes') {
    $ac['LANG'] = 'en';
}
if ($ac["LANG"] == "en") {
    $ac["TRANSLATION_ONLY_INCL_BEGIN"] = "<!--";
    $ac["TRANSLATION_ONLY_INCL_END"] = "-->";
}
checkvalue($ac['LANG']);

checking("whether the language is supported");
$LANGDIR = "{$ac['rootdir']}/{$ac['LANG']}";
if (file_exists("{$LANGDIR}/trunk")) {
    $LANGDIR .= '/trunk';
}
if (!file_exists($LANGDIR) || !is_readable($LANGDIR)) {
    checkerror("No language directory found.");
}

$ac['LANGDIR'] = basename($LANGDIR);
if ($ac['LANGDIR'] == 'trunk') {
    $ac['LANGDIR'] = '../' . basename(dirname($LANGDIR)) . '/trunk';
    $ac['EN_DIR'] = '../en/trunk';
} else {
    $ac['EN_DIR'] = 'en';
}
checkvalue("yes");

checking("for partial build");
checkvalue($ac['PARTIAL']);

checking('whether to enable detailed XML error messages');
checkvalue($ac['DETAILED_ERRORMSG']);

checking('libxml version');
checkvalue(LIBXML_DOTTED_VERSION);

checking('whether to enable detailed error reporting (may segfault)');
checkvalue($ac['SEGFAULT_ERROR']);

if ($ac["GENERATE"] != "no") {
    $ac["ONLYDIR"] = dirname(realpath($ac["GENERATE"]));
}


// We shouldn't be globbing for this. autoconf requires you to tell it which files to use, we should do the same
// Notice how doing it this way results in generating less than half as many files.
$infiles = array(
    'manual.xml.in',
    'scripts/file-entities.php.in',
);

// Show local repository status to facilitate debug

$repos = array();
$repos['doc-base']  = $ac['basedir'];
$repos['en']        = "{$ac['rootdir']}/{$ac['EN_DIR']}";
$repos[$ac['LANG']] = "{$ac['rootdir']}/{$ac['LANG']}";
$repos = array_unique($repos);

foreach ($repos as $name => $path)
{
    $driveSwitch = is_windows() ? '/d' : '';
    $output = str_pad( "$name:" , 10 );
    $output .= `cd $driveSwitch $path && git rev-parse HEAD`;
    $output .= `cd $driveSwitch $path && git status -s`;
    $output .= `cd $driveSwitch $path && git for-each-ref --format="%(push:track)" refs/heads`;
    echo trim($output) . "\n";
}
echo "\n";

foreach ($infiles as $in) {
    $in = chop("{$ac['basedir']}/{$in}");

    $out = substr($in, 0, -3);
    echo "Generating {$out}... ";
    if (generate_output_file($in, $out, $ac)) {
        echo "done\n";
    } else {
        echo "fail\n";
        errors_are_bad(117);
    }
}

if ($ac['SEGFAULT_ERROR'] === 'yes') {
    libxml_use_internal_errors(true);
}

$compact = defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0;
$big_lines = defined('LIBXML_BIGLINES') ? LIBXML_BIGLINES : 0;
$LIBXML_OPTS = LIBXML_NOENT | $big_lines | $compact;

if ($ac['VERSION_FILES'] === 'yes') {
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput       = true;

    $tmp = new DOMDocument;
    $tmp->preserveWhiteSpace = false;

    $versions = $dom->appendChild($dom->createElement("versions"));


    echo "Iterating over extension specific version files... ";
    if ($ac["GENERATE"] != "no") {
        $globdir = dirname($ac["GENERATE"]) . "/{../../}versions.xml";
    }
    else {
        if (file_exists($ac['rootdir'] . '/en/trunk')) {
            $globdir = $ac['rootdir'] . '/en/trunk';
        } else {
            $globdir = $ac['rootdir'] . '/en';
        }
        $globdir .= "/*/*/versions.xml";
    }
    if (!defined('GLOB_BRACE')) {
        define('GLOB_BRACE', 0);
    }
    foreach(glob($globdir, GLOB_BRACE) as $file) {
        if($tmp->load($file)) {
            foreach($tmp->getElementsByTagName("function") as $function) {
                $function = $dom->importNode($function, true);
                $versions->appendChild($function);
            }
        } else {
            print_xml_errors();
            errors_are_bad(1);
        }
    }
    echo "OK\n";
    echo "Saving it... ";

    if ($dom->save($ac['srcdir'] . '/version.xml')) {
        echo "OK\n";
    } else {
        echo "FAIL!\n";
    }
}

if ($ac['SOURCES_FILE'] === 'yes') {
    generate_sources_file();
}

$history_file = [];
if ($ac['HISTORY_FILE'] === 'yes') {
    $history_file = getFileModificationHistory();
}

globbetyglob("{$ac['basedir']}/scripts", 'make_scripts_executable');

$redir = ($ac['quiet'] == 'yes') ? ' > ' . (is_windows() ? 'nul' : '/dev/null') : '';

quietechorun("\"{$ac['PHP']}\" -q \"{$ac['basedir']}/scripts/file-entities.php\"{$redir}");


checking("for if we should generate a simplified file");
if ($ac["GENERATE"] != "no") {
    if (!file_exists($ac["GENERATE"])) {
        checkerror("Can't find {$ac["GENERATE"]}");
    }
    $tmp = realpath($ac["GENERATE"]);
    $ac["GENERATE"] = str_replace($ac["ROOTDIR"].$ac["LANGDIR"], "", $tmp);
    $str = "\n<!ENTITY developer.include.file SYSTEM 'file:///{$ac["GENERATE"]}'>";
    file_put_contents("{$ac["basedir"]}/entities/file-entities.ent", $str, FILE_APPEND);
    $ac["FORCE_DOM_SAVE"] = "yes";
}
checkvalue($ac["GENERATE"]);

checking('whether to save an invalid .manual.xml');
checkvalue($ac['FORCE_DOM_SAVE']);

echo "Loading and parsing {$ac["INPUT_FILENAME"]}... ";
flush();

$dom = new DOMDocument();

// realpath() is important: omitting it causes severe performance degradation
// and doubled memory usage on Windows.
$didLoad = $dom->load(realpath("{$ac['srcdir']}/{$ac["INPUT_FILENAME"]}"), $LIBXML_OPTS);

// Check if the XML was simply broken, if so then just bail out
if ($didLoad === false) {
    echo "failed.\n";
    print_xml_errors();
    errors_are_bad(1);
}
echo "done.\n";

echo "Running XInclude/XPointer... ";
$status = $dom->xinclude();
if ($status === -1) {
    echo "failed.\n";
} else {
    /* For some dumb reason when no substitution are made it returns false instead of 0... */
    $status = (int) $status;
    echo "done. Performed $status XIncludes\n";
}
flush();

if ( $ac['XPOINTER_REPORTING'] == 'yes' || $ac['LANG'] == 'en' )
{
    $errors = libxml_get_errors();
    $output = ( $ac['STDERR_TO_STDOUT'] == 'yes' ) ? STDOUT : STDERR;
    if ( count( $errors ) > 0 )
    {
        fprintf( $output , "\n");
        foreach( $errors as $error )
            fprintf( $output , "{$error->message}\n");
        if ( $ac['LANG'] == 'en' )
            errors_are_bad(1);
    }
}

echo "Validating {$ac["INPUT_FILENAME"]}... ";
flush();
if ($ac['PARTIAL'] != '' && $ac['PARTIAL'] != 'no') { // {{{
    $dom->relaxNGValidate(RNG_SCHEMA_FILE); // we don't care if the validation works or not
    $node = $dom->getElementById($ac['PARTIAL']);
    if (!$node) {
        echo "failed.\n";
        echo "Failed to find partial ID in source XML: {$ac['PARTIAL']}\n";
        errors_are_bad(1);
    }
    if ($node->tagName !== 'book' && $node->tagName !== 'set') {
        // this node is not normally allowed here, attempt to wrap it
        // in something else
        $parents = array();
        switch ($node->tagName) {
            case 'refentry':
                $parents[] = 'reference';
                // Break omitted intentionally
            case 'part':
                $parents[] = 'book';
                break;
        }
        foreach ($parents as $name) {
            $newNode = $dom->createElement($name);
            $newNode->appendChild($node);
            $node = $newNode;
        }
    }
    $set = $dom->documentElement;
    $set->nodeValue = '';
    $set->appendChild($dom->createElement('title', 'PHP Manual (Partial)')); // prevent validate from complaining unnecessarily
    $set->appendChild($node);

    $filename = "{$ac['srcdir']}/.manual.{$ac['PARTIAL']}.xml";
    $dom->save($filename);
    echo "done.\n";
    echo "Partial manual saved to {$filename}. To build it, run 'phd -d {$filename}'\n";
    exit(0);
} // }}}

$mxml = $ac["OUTPUT_FILENAME"];

/* TODO: For some reason libxml does not validate the RelaxNG schema unless reloading the document in full */
$dom->save($mxml);
$dom->load($mxml, $LIBXML_OPTS);
if ($dom->relaxNGValidate(RNG_SCHEMA_FILE)) {
    echo "done.\n";
    printf("\nAll good. Saving %s... ", basename($ac["OUTPUT_FILENAME"]));
    flush();
    $dom->save($mxml);

    echo "done.\n";
    echo "All you have to do now is run 'phd -d {$mxml}'\n";
    echo "If the script hangs here, you can abort with ^C.\n";
    echo <<<CAT
         _ _..._ __
        \)`    (` /
         /      `\
        |  d  b   |
        =\  Y    =/--..-="````"-.
          '.=__.-'               `\
             o/                 /\ \
              |                 | \ \   / )
               \    .--""`\    <   \ '-' /
              //   |      ||    \   '---'
         jgs ((,,_/      ((,,___/


CAT;

    if (function_exists('proc_nice') && !is_windows()) {
        echo " (Run `nice php $_SERVER[SCRIPT_NAME]` next time!)\n";
    }

    exit(0); // Tell the shell that this script finished successfully.
} else {
    echo "failed.\n";
    echo "\nThe document didn't validate\n";

    /**
     * TODO: Integrate jing to explain schema violations as libxml is *useless*
     * And this is not going to change for a while as the maintainer of libxml2 even acknowledges:
     * > As it stands, libxml2's Relax NG validator doesn't seem suitable for production.
     * cf. https://gitlab.gnome.org/GNOME/libxml2/-/issues/448
     */
    $output = shell_exec('java -jar ' . $srcdir . '/docbook/jing.jar ' . RNG_SCHEMA_FILE. ' ' . $acd['OUTPUT_FILENAME']);
    if ($output === null) {
        echo "Command failed do you have Java installed?";
    } else {
        echo $output;
    }
    //echo 'Please use Jing and the:' . PHP_EOL
    //    . 'java -jar ./build/jing.jar /path/to/doc-base/docbook/docbook-v5.2-os/rng/docbookxi.rng /path/to/doc-base/.manual.xml' . PHP_EOL
    //    . 'command to check why the RelaxNG schema failed.' . PHP_EOL;

    // Exit normally when don't care about validation
    if ($ac["FORCE_DOM_SAVE"] == "yes") {
        exit(0);
    }

    errors_are_bad(1); // Tell the shell that this script finished with an error.
}
?>
