<?php
include_once("record_uploader.php");

$old_error_handler = set_error_handler("myErrorHandler");
$unit_tests = new Unit_Tests();
$unit_test_methods = get_class_methods("Unit_Tests");
foreach ($unit_test_methods as $test) {
   $unit_tests->$test();
}
echo "All unit tests have been run.";

// modified version of code available at
// http://stackoverflow.com/questions/3316899/try-catch-with-php-warnings
function myErrorHandler($errno, $errstr, $errfile, $errline)
{
    $file_parts = explode('/', $errfile);
    $file_name = $file_parts[count($file_parts) - 1];
    switch ($errno) {
        case E_USER_WARNING:    echo "<b>My WARNING</b> [$errno] $errstr<br />\n";  break;
        case E_USER_NOTICE:     echo "<b>My NOTICE</b> [$errno] $errstr<br />\n";   break;
        case E_WARNING:         echo "Assert failed - " . $file_name . " (" . $errline . ") - " . $errstr . "<br>\n"; break;
        default:                echo "Unknown error type: [$errno] $errstr<br />\n";break;
    }
    /* Do execute PHP internal error handler */
    return false;
}

class Unit_Tests {

    private $default_processor_config;
    private $test_success_count;

    function __construct() {
        $this->default_processor_config = array( 'table' => 'test', 'column' => 'test' );
    }

    function test_strip_spaces() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $vp = new Value_Processor($db, $processor_config);
        assert( "123" == $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::BEFORE;
        $vp = new Value_Processor($db, $processor_config);
        assert( "123  " == $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::AFTER;
        $vp = new Value_Processor($db, $processor_config);
        assert( "  123" == $vp->process_value("  123  "), "problem stripping whitespace.");
    }

    function test_date_validator() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Date_Validator_Formatter();
        $vp = new Value_Processor($db, $processor_config);
        assert( "2012-3-22" == $vp->process_value("22/3/2012"), "problem validating date.");
        assert( "2012-3-22" == $vp->process_value("22-3-2012"), "problem validating date.");
        assert( "2012-3-22" == $vp->process_value("22-Mar-2012"), "problem validating date.");
        assert( "2012-3-22" == $vp->process_value("22-mar-2012"), "problem validating date.");
        assert( "2012-3-22" == $vp->process_value("22-MAR-2012"), "problem validating date.");

        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Date_Validator_Formatter(array(Date_Parts::YEAR, Date_Parts::MONTH, Date_Parts::DAY));
        $vp = new Value_Processor($db, $processor_config);
        assert( "2012-4-22" == $vp->process_value("2012-apr-22"), "problem validating date.");
        try {
            $vp->process_value("22-MAZ-2012");
            assert(false, "should not get here, should have errored into catch block");
        } catch (Exception $ex) {
            assert( "Error with month." == $ex->getMessage(), "Caught the wrong error.");
        }

        try {
            $vp->process_value("232-MAR-2012");
            assert(false, "should not get here, should have errored into catch block");
        } catch (Exception $ex) {
            assert( "Error with date formatting." == $ex->getMessage(), "Caught the wrong error from date formatter.");
        }
        
    }

}
?>
