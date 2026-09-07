#!/usr/bin/env php
<?php
 /**
  * A JS and CSS minifier for projects using the Smarty PHP templating engine
  *
  * Released under the BSD License.
  *
  * Copyright (c) 2010 - 2012, Open Source Solutions Limited, Dublin, Ireland <http://www.opensolutions.ie>.
  * All rights reserved.
  *
  * Redistribution and use in source and binary forms, with or without modification, are permitted
  * provided that the following conditions are met:
  *
  *  - Redistributions of source code must retain the above copyright notice, this list of
  *    conditions and the following disclaimer.
  *  - Redistributions in binary form must reproduce the above copyright notice, this list
  *    of conditions and the following disclaimer in the documentation and/or other materials
  *    provided with the distribution.
  *
  * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS
  * OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
  * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL
  * THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
  * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
  * GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
  * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
  * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
  * OF THE POSSIBILITY OF SUCH DAMAGE.
  */


  /**
   * This file contains configurable options for minify.php which should sit in the same directory
   * as minify.php
   */

// Regenerating a bundle needs Closure Compiler at bin/compiler.jar. It is a
// per-machine build prerequisite rather than a vendored 13MB binary:
//
//   curl -fsSL -o bin/compiler.jar \
//     https://repo1.maven.org/maven2/com/google/javascript/closure-compiler/v20230802/closure-compiler-v20230802.jar
//   printf '%s  %s\n' \
//     230a9e05a8a7d9daa083b1f6e86edba6eb1ec6402a6a258432fe4245cdc4a95f \
//     bin/compiler.jar | sha256sum -c -
//
// Verify the digest before Java runs the file: the bundle it emits is shipped
// to every browser, so an unverified compiler is a supply-chain hole.
//
// Run it as:
//
//   php vendor/opensolutions/minify/minify.php --conf "$PWD/bin/minify-options.php" --version 18
//
// Two traps: --version takes the bare number ('18'), because the 'v' prefix is
// added for you; and this file resets $whatToCompress below AFTER the command
// line is parsed, so --js-only does not stop CSS being regenerated. Restore the
// CSS bundle and header-css.phtml afterwards if you only meant to rebuild JS.

// By default, compress both JS and CSS - can be over ridden by the command line
$whatToCompress = 'all';

// by default, be quiet
$verbose = true;

// We use APPLICATION_PATH as per the Zend framework. Feel free to remove as it's only used for the paths defined below here
defined( 'APPLICATION_PATH' ) || define( 'APPLICATION_PATH', realpath( __DIR__ . '/../application' ) );
// vendor/bin/minify.php defines this for normal use; keep the config loadable on
// its own as well (for validation and custom runners).
defined( 'SCRIPTDIR' ) || define( 'SCRIPTDIR', __DIR__ );


/////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////
//
// JS Configuration
// --language_in ECMASCRIPT5: the default ECMASCRIPT3 mode reserves identifiers
// (e.g. `final`) that jQuery 3.7.1 uses as ordinary variable names, so the
// compiler otherwise fails to parse it even in WHITESPACE_ONLY mode.
$js_compiler = "java -jar " . SCRIPTDIR . "/compiler.jar --compilation_level WHITESPACE_ONLY --warning_level QUIET --language_in ECMASCRIPT5";


// JavaScript files to compress
//
// We name all files with a 3 digit prefix such as:
//    001-a-js-file.js
//    800-another.js
// and then glob() the following and sort numerically when creating the bundle:
$js_files = APPLICATION_PATH . '/../public/js/[0-9][0-9][0-9]-*.js';

// stick the files here
$js_dest = APPLICATION_PATH . '/../public/js';

// http reference as to where to find your JS files. We have a defined Smarty
// function called {genUrl} which builds up the URL and takes account of http/s
// automatically. You can just as easily put '/myapp/js/' here for example
$http_js = '{genUrl}/js';

// In our application, we define a var as 0 or 1 where 1 means use the bundle and 0
// means use the individual uncompressed files. I.e. production vs development. The
// script then spits out a Smarty (in this case) template file we can include which
// is aware of the var and uses the individual files or the bundle as appropriate.
//
// For this, we need the components of an if/else clause. I.e.
//
// if( use bundle )
//    <include bundle file>
// else
//    <include original file1>
//    <include original file2>
//    ....
// endif
//
// For Smarty, the follow works so long as you set $config.use_minified_js

// jQuery Migrate (public/js/jquery-migrate-3.5.2.js) is deliberately named
// without an NNN- prefix so it falls OUTSIDE the $js_files glob above: it must
// never ship in the production bundle, only ever load in dev so every
// deprecation warning surfaces there. Since the glob can't express that, the
// dev-only <script> row for it is hand-appended after the glob-generated rows
// here, so each `minify.php` regeneration reproduces it rather than dropping
// it. It only needs to load before any code *calls* a shimmed API at runtime
// (event handlers etc.), not immediately after jquery.js itself, so appending
// it last (after every other dev-mode <script> row) is safe.
$mini_js_conditional_if   = '{if isset( $config.use_minified_js ) and $config.use_minified_js}';
$mini_js_conditional_else = '{else}';
$mini_js_conditional_end  = '    <script type="text/javascript" src="' . $http_js . '/jquery-migrate-3.5.2.js"></script>
{/if}';

//
// set the following to false to not use this functionality and maintain it yourself

// $js_header_file = false;

$js_header_file = APPLICATION_PATH . '/views/header-js.phtml';

// We create a minified version of each JS file found. These can safely be deleted:
$del_mini_js = true;

// do we want to keep older minified JS files? If you have old installs taking JS/CSS
// from a CDN / central repository you may want to keep these and delete manually
$del_old_js_bundles = true;





/////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////
//
// CSS Configuration
$css_compiler = "java -jar " . SCRIPTDIR . "/yuicompressor.jar -v --charset utf-8";

// JavaScript files to compress
//
// We name all files with a 3 digit prefix such as:
//    001-a-css-file.css
//    800-another.css
// and then glob() the following and sort numerically when creating the bundle:
$css_files = APPLICATION_PATH . '/../public/css/[0-9][0-9][0-9]-*.css';

// stick the files here
$css_dest = APPLICATION_PATH . '/../public/css';

// http reference as to where to find your CSS files. We have a defined Smarty
// function called {genUrl} which builds up the URL and takes account of http/s
// automatically. You can just as easily put '/myapp/js/' here for example
$http_css = '{genUrl}/css';

// See $mini_js_conditional_ above for an explanation

// The skin-override stylesheet is a hand-written block that must survive
// every regeneration: it is unconditional (applies after the bundle/dev
// {if}/{else}), loaded last so it wins, and gated at runtime on $skinCss
// rather than on $config.use_minified_css. See
// application/views/_skins/dark and src/Kernel/Bootstrap.php::skinCss. It is
// folded into $mini_css_conditional_end (mirroring the JS-side Migrate
// technique above) so `minify.php` reproduces it instead of silently
// dropping it.
$mini_css_conditional_if   = '{if isset( $config.use_minified_css ) and $config.use_minified_css}';
$mini_css_conditional_else = '{else}';
$mini_css_conditional_end  = '{/if}

{* Skin override stylesheet, loaded last so it wins. Enable via
   resources.smarty.skin in application.ini + drop the file at
   public/css/_skins/<skin>/skin.css. See contrib/THEMING.md. *}
{if isset( $skinCss ) && $skinCss}
    <link rel="stylesheet" type="text/css" href="{$skinCss|escape}" />
{/if}';

//
// set the following to false to not use this functionality and maintain it yourself

// $css_header_file = false;

$css_header_file = APPLICATION_PATH . '/views/header-css.phtml';

// We create a minified version of each CSS file found. These can safely be deleted:
$del_mini_css = true;

// do we want to keep older minified CSS files? If you have old installs taking JS/CSS
// from a CDN / central repository you may want to keep these and delete manually
$del_old_css_bundles = true;

