<?php

function include_all_tests($folder){
    foreach (glob("{$folder}/*.php") as $filename)
    {
        include $filename;
    }
}

// TODO - this currently isn't working, not sure how to access classes declared in
// an included script file, going to explicitly run the project specific tests seprately
// for now
$unit_test_classes = array();
$unit_test_classes[] = new Unit_Tests();
/*
foreach( get_declared_classes() as $class ) {
    if ($class instanceof Unit_Tests) {
         $unit_test_classes[] = $class;
    }
}
*/

include_once("record_uploader.php");
include_all_tests("../user/UDFs/tests");

$old_error_handler = set_error_handler("myErrorHandler");
foreach ($unit_test_classes as $unit_test_class) {
    $unit_tests = new $unit_test_class();
    $unit_test_methods = get_class_methods($unit_tests);
    foreach ($unit_test_methods as $test) {
       $unit_tests->$test();
    }
}
echo "All SYSTEM unit tests have been run.<br>\n";

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

    private function assertEquals($expected, $actual, $message = "") {
        assert($expected == $actual, "Error: expected value '". $expected . "' but received '" . $actual . "'"
                . ($message != "" ? " - " . $message : "") ); 
    }

    function test_strip_spaces() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $vp = new Value_Processor($db, $processor_config);
        $this->assertEquals( "123" == $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::BEFORE;
        $vp = new Value_Processor($db, $processor_config);
        $this->assertEquals( "123  " == $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::AFTER;
        $vp = new Value_Processor($db, $processor_config);
        $this->assertEquals( "  123" == $vp->process_value("  123  "), "problem stripping whitespace.");
    }

    function test_date_validator() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Date_Validator_Formatter();
        $vp = new Value_Processor($db, $processor_config);
        $this->assertEquals( "2012-3-22", $vp->process_value("22/3/2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-3-2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-Mar-2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-mar-2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-MAR-2012"), "problem validating date.");

        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Date_Validator_Formatter(array(Date_Parts::YEAR, Date_Parts::MONTH, Date_Parts::DAY));
        $vp = new Value_Processor($db, $processor_config);
        $this->assertEquals( "2012-4-22", $vp->process_value("2012-apr-22"), "problem validating date.");
        try {
            $vp->process_value("22-MAZ-2012");
            throw new Exception("Should not get here, should have errored into catch block.");
        } catch (Exception $ex) {
            $this->assertEquals( "Error with month.", $ex->getMessage(), "Caught the wrong error.");
        }

        try {
            $vp->process_value("232-MAR-2012");
            throw new Exception("Should not get here, should have errored into catch block.");
        } catch (Exception $ex) {
            $this->assertEquals( "Error with date formatting.", $ex->getMessage(), "Caught the wrong error from date formatter.");
        }
        
    }

    function test_time_validator() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Time_Validator_Formatter();
        $vp = new Value_Processor($db, $processor_config);
        $this->assertEquals( "2:30", $vp->process_value("2:30"), "problem validating time.");
        // test the default chain of space removal before other modifiers
        $this->assertEquals( "2:30", $vp->process_value("2:30  "), "problem validating time.");
    }

    function repeater_validator() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Value_Repeater();
        $processor_config['modifiers'][] = new Time_Validator_Formatter();
        $vp = new Value_Processor($db, $processor_config);
        try {
            $vp->process_value("");
        } catch (Exception $ex) {
            $this->assertEquals("No value provided for repeating column.", $ex->getMessage(), "Wrong error returned from test of repeater.");
        }
        $this->assertEquals( "2:30", $vp->process_value("2:30"), "problem validating time.");
        $this->assertEquals( "2:30", $vp->process_value(" "), "problem validating time.");
        $this->assertEquals( "2:30", $vp->process_value(""), "problem validating time.");
    }
}
?>
