<?php
/**
 * Test that Bootstrap 5 spinners replace the old Throbber.js library
 */

class Test_SpinnerReplacement
{
    private $base_dir;
    private $failures = array();

    public function __construct()
    {
        $this->base_dir = dirname(__FILE__) . '/..';
    }

    /**
     * Run all tests
     */
    public function run()
    {
        echo "Running Spinner Replacement Tests...\n";
        $this->test_throbber_library_removed();
        $this->test_throbber_images_removed();
        $this->test_minify_inputs_no_throbber();
        $this->test_header_no_throbber_tag();
        $this->test_about_page_credits_updated();
        $this->test_wrapper_function_uses_spinner();

        if ( empty($this->failures) )
        {
            echo "All tests passed!\n";
            return 0;
        }
        else
        {
            echo "Tests failed:\n";
            foreach ( $this->failures as $failure )
            {
                echo "  FAIL: $failure\n";
            }
            return 1;
        }
    }

    /**
     * Test that old Throbber library file is removed
     */
    private function test_throbber_library_removed()
    {
        $throbber_path = $this->base_dir . '/public/js/310-throbber.js';
        if ( file_exists($throbber_path) )
        {
            $this->failures[] = 'Throbber.js library should be removed';
        }
        else
        {
            echo "  OK: Throbber library removed\n";
        }
    }

    /**
     * Test that throbber images are removed
     */
    private function test_throbber_images_removed()
    {
        $gif_16 = $this->base_dir . '/public/images/throbber_16px.gif';
        $gif_32 = $this->base_dir . '/public/images/throbber_32px.gif';

        if ( file_exists($gif_16) )
        {
            $this->failures[] = 'throbber_16px.gif should be removed';
        }
        else
        {
            echo "  OK: throbber_16px.gif removed\n";
        }

        if ( file_exists($gif_32) )
        {
            $this->failures[] = 'throbber_32px.gif should be removed';
        }
        else
        {
            echo "  OK: throbber_32px.gif removed\n";
        }
    }

    /**
     * Test that minify bundle input no longer registers throbber
     */
    private function test_minify_inputs_no_throbber()
    {
        $inputs_file = $this->base_dir . '/bin/minify-bundle-files.php';
        $content = file_get_contents($inputs_file);

        if ( strpos($content, '310-throbber.js') !== false )
        {
            $this->failures[] = 'minify-bundle-files.php should not register 310-throbber.js';
        }
        else
        {
            echo "  OK: minify-bundle-files.php cleaned\n";
        }
    }

    /**
     * Test that header includes no throbber script tag
     */
    private function test_header_no_throbber_tag()
    {
        $header_file = $this->base_dir . '/application/views/header-js.phtml';
        $content = file_get_contents($header_file);

        if ( strpos($content, '310-throbber.js') !== false )
        {
            $this->failures[] = 'header-js.phtml should not load 310-throbber.js';
        }
        else
        {
            echo "  OK: header-js.phtml cleaned\n";
        }
    }

    /**
     * Test that about page credits no longer mention throbber
     */
    private function test_about_page_credits_updated()
    {
        $about_file = $this->base_dir . '/application/views/index/about.phtml';
        $content = file_get_contents($about_file);

        if ( strpos($content, 'throbber.js') !== false )
        {
            $this->failures[] = 'about.phtml should not reference throbber.js in credits';
        }
        else
        {
            echo "  OK: about.phtml credits updated\n";
        }
    }

    /**
     * Test that wrapper function uses spinner classes
     */
    private function test_wrapper_function_uses_spinner()
    {
        $js_file = $this->base_dir . '/public/js/990-vimbadmin.js';
        $content = file_get_contents($js_file);

        if ( strpos($content, "spinner-border") === false )
        {
            $this->failures[] = '990-vimbadmin.js should use spinner-border class';
        }
        else
        {
            echo "  OK: 990-vimbadmin.js uses spinner-border\n";
        }

        if ( strpos($content, "new Throbber") !== false )
        {
            $this->failures[] = '990-vimbadmin.js should not use new Throbber()';
        }
        else
        {
            echo "  OK: 990-vimbadmin.js does not use Throbber\n";
        }
    }
}

$test = new Test_SpinnerReplacement();
exit( $test->run() );
