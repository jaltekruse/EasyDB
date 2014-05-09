<?php

include_once("record_processor.php");
include_once("../user/user_config.php");
include_all_tests("../user/UDFs/tests");

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

try{
    $user_config_txt = file_get_contents("test_files/basic_config.json");
} catch ( Exception $ex ) {
    throw new Exception("Error loading user perferences.", $ex);
}
$user_config_parameters = json_decode($user_config_txt, true /* parse into associative arrays*/);
$user_config = new User_Config($user_config_parameters);
$unit_test_classes[] = new Unit_Tests($user_config);
/*
foreach( get_declared_classes() as $class ) {
    if ($class instanceof Unit_Tests) {
         $unit_test_classes[] = $class;
    }
}
*/

// TODO - user config is available in the global namesapce right now, may want to move it
$old_error_handler = set_error_handler("myErrorHandler");
foreach ($unit_test_classes as $unit_tests) {
    $unit_test_methods = get_class_methods($unit_tests);
    foreach ($unit_test_methods as $test) {
        if ( strpos($test, 'test') !== FALSE ){
            echo "Running test: " . $test . "<br>\n"; 
            $unit_tests->$test();
        }
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
        default:                echo "Unknown error type: [$errno] in file: " . $file_name . " (" . $errline . ") - " . $errstr . "<br>\n"; break;
    }
    /* Do execute PHP internal error handler */
    return false;
}

class Unit_Tests {

    protected $default_processor_config;
    protected $default_db;
    protected $test_success_count;
    protected $user_config;

    function __construct($user_config) {
        $this->default_processor_config = array( 'column' => 'test' );
        $this->user_config = $user_config;
    }

    protected function assertEquals($expected, $actual, $message = "") {
        if ($expected != $actual) {
            echo "Error: expected value '";
            print_r($expected);
            echo "' but received '";
            print_r($actual);
            echo "'" . ($message != "" ? " - " . $message : "");
        }
    }

    function test_column_combiner_processor() {
        $db = 1;
        $val_processors = array();
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $val_processors[] = new Value_Processor($db, $this->user_config, $processor_config);
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Time_Validator_Formatter());
        $val_processors[] = new Value_Processor($db, $this->user_config, $processor_config);
        
        $data_output = new Column_Combiner_Output($val_processors, "date_time");

        $record_processor = new Record_Processor(array('data_outputs' => array($data_output)));
        $record_processor->process_row(array("22/3/2012", "2:30"));
        $this->assertEquals("2012-3-22 2:30", $record_processor->get_outputs()[0]->get_last_val());
        $this->assertEquals(array("2012-3-22 2:30"), $record_processor->output_to_array());
    }

    function test_record_processor() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Time_Validator_Formatter());
        $data_output = new Single_Column_Output( new Value_Processor($db, $this->user_config, $processor_config), "time");
        $data_output2 = new Single_Column_Output( new Value_Processor($db, $this->user_config, $processor_config), "time2");

        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $data_output3 = new Single_Column_Output(new Value_Processor($db, $this->user_config, $processor_config),'date');

        $record_processor = new Record_Processor(array('data_outputs' => array($data_output, $data_output2, $data_output3)));
        try {
            $record_processor->process_row(array("2:30", "4:30", "22asdf/3/2012"));
        } catch (Exception $ex) {
            $this->assertEquals("Exception processing value: Error with date formatting.", $ex->getMessage(), "Recieved wrong error message.");
        }
        try {
            $record_processor->process_row(array("2:30", "4:30", "22/3/2012", "extra_column"));
        } catch (Exception $ex) {
            $this->assertEquals("Unexpected extra input at the end of row, starting at 'extra_column'", $ex->getMessage(), "Recieved wrong error message.");
        }
        $record_processor->process_row(array("2:30", "4:30", "22/3/2012"));
        $this->assertEquals("2:30", $record_processor->get_outputs()[0]->get_last_val());
        $this->assertEquals("4:30", $record_processor->get_outputs()[1]->get_last_val());
        $this->assertEquals("2012-3-22", $record_processor->get_outputs()[2]->get_last_val());
        $this->assertEquals(array("2:30", "4:30", "2012-3-22"), $record_processor->output_to_array());
    }

    function test_code_value_validator(){
        $db = $this->user_config->get_database_connection();

        $result = $db->query("DROP TABLE IF EXISTS `mbed`.`animals_easy_db_test_temp`");
        if ( ! $result ) throw new Exception("Error adding test table to database: " . $db->error);
        $result = $db->query("
            CREATE TABLE IF NOT EXISTS `mbed`.`animals_easy_db_test_temp` (
              `animal_id` INT NOT NULL AUTO_INCREMENT,
              `animal_code` VARCHAR(45) NOT NULL,
              PRIMARY KEY (`animal_id`),
              UNIQUE INDEX `animal_code_UNIQUE` (`animal_code` ASC)
            ) ENGINE = InnoDB");
        if ( ! $result ) throw new Exception("Error adding test table to database: " . $db->error);
        $result = $db->query("insert into animals_easy_db_test_temp  (animal_code) values ('animal_code')");
        if ( ! $result ) throw new Exception("Error adding test data: " . $db->error);
        $result = $db->query("insert into animals_easy_db_test_temp  (animal_code) values ('animal_code2')");
        if ( ! $result ) throw new Exception("Error adding test data: " . $db->error);
        
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Code_Value_Validator('animals_easy_db_test_temp'));
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $vp->init("test_record_table");
        $this->assertEquals( 1, $vp->process_value("animal_code"), "Error getting corret key from a code value table");
        $this->assertEquals( 2, $vp->process_value("animal_code2"), "Error getting corret key from a code value table");
        $db->query("DROP TABLE IF EXISTS `mbed`.`animals_easy_db_test_temp`");
    }

    function test_strip_spaces() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "123" == $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::BEFORE;
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "123  " == $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::AFTER;
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "  123" == $vp->process_value("  123  "), "problem stripping whitespace.");
    }

    function test_date_validator() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "2012-3-22", $vp->process_value("22/3/2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-3-2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-Mar-2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-mar-2012"), "problem validating date.");
        $this->assertEquals( "2012-3-22", $vp->process_value("22-MAR-2012"), "problem validating date.");

        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Date_Validator_Formatter(array(Date_Parts::YEAR, Date_Parts::MONTH, Date_Parts::DAY)));
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
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
        $processor_config['modifiers'] = array(new Time_Validator_Formatter());
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "2:30", $vp->process_value("2:30"), "problem validating time.");
        // test the default chain of space removal before other modifiers
        $this->assertEquals( "2:30", $vp->process_value("2:30  "), "problem validating time.");
    }

    function test_repeater_validator() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array();
        $processor_config['modifiers'][] = new Value_Repeater();
        $processor_config['modifiers'][] = new Time_Validator_Formatter();
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
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
