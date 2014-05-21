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
$unit_test_classes[0]->init_tests();
foreach ($unit_test_classes as $unit_tests) {
    $unit_test_methods = get_class_methods($unit_tests);
    foreach ($unit_test_methods as $test) {
        // tests must start with the word test
        if ( strpos($test, 'test') === 0 ){
            echo "Running test: " . $test . "<br>\n"; 
            try {
                $unit_tests->$test();
            } catch (Exception $ex){
                echo $ex->getMessage() . "<br>\n";
            }
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
            echo "Error: expected value <br>'";
            print_r($expected);
            echo "'<br> but received <br>'";
            print_r($actual);
            echo "'" . ($message != "" ? " - " . $message : "");
        }
    }

    function init_tests() {
        $db = $this->user_config->get_database_connection();
        // add some temporary tables to be used in the tests, they are removed in the test_clenaup method
        $drop_table_1 = "DROP TABLE `mbed`.`animals_easy_db_test_temp`";
        $drop_table_2 = "DROP TABLE `mbed`.`age_categories_easy_db_test_temp`";
        $create_table = 
            "CREATE TABLE IF NOT EXISTS `mbed`.`age_categories_easy_db_test_temp` (
              `age_category_id` INT NOT NULL AUTO_INCREMENT,
              `age_category` VARCHAR(45) NOT NULL,
              `date_added` VARCHAR(45) NOT NULL,
              PRIMARY KEY (`age_category_id`),
              UNIQUE INDEX `age_category_UNIQUE` (`age_category` ASC),
              UNIQUE INDEX `age_category_id_UNIQUE` (`age_category_id` ASC))
            ENGINE = InnoDB";
        $create_table_2 = 
            "CREATE TABLE IF NOT EXISTS `mbed`.`animals_easy_db_test_temp` (
              `animal_id` INT NOT NULL AUTO_INCREMENT,
              `birthday` DATETIME NULL,
              `is_male` TINYINT(1) NULL,
              `age_category_id` INT NULL,
              `animal_code` VARCHAR(45) NOT NULL,
              PRIMARY KEY (`animal_id`),
              INDEX `fk_animals_age_categories1` (`age_category_id` ASC),
              UNIQUE INDEX `animal_code_UNIQUE` (`animal_code` ASC),
              CONSTRAINT `fk_animals_age_categories2`
                FOREIGN KEY (`age_category_id`)
                REFERENCES `mbed`.`age_categories_easy_db_test_temp` (`age_category_id`)
                ON DELETE NO ACTION
                ON UPDATE NO ACTION)
            ENGINE = InnoDB";
        $result = $db->query($drop_table_1);
        if ( ! $result ) throw new Exception("Error dropping test table from database: " . $db->error);
        $result = $db->query($drop_table_2);
        if ( ! $result ) throw new Exception("Error dropping test table from database: " . $db->error);
        $result = $db->query($create_table);
        if ( ! $result ) throw new Exception("Error adding test table to database: " . $db->error);
        $result = $db->query($create_table_2);
        if ( ! $result ) throw new Exception("Error adding test table to database: " . $db->error);
    }

    function test_duplicate_check(){

    }

    function test_insert() {
        $db = $this->user_config->get_database_connection();
        $user_config = $this->user_config;

        $result = $db->query("delete from animals_easy_db_test_temp where animal_code = 'jimmy'");
        if ( ! $result ) echo 'Error with pre-test record deletion :' . $db->error . '<br>';

        $result = $db->query("delete from age_categories_easy_db_test_temp where age_category = 'elderly'");
        if ( ! $result ) echo 'Error with pre-test record deletion :' . $db->error . '<br>';

        // create an entry in the age categories table
        $data_outputs = array(
            // date time
            new Single_Column_Output( new Value_Processor($db, $user_config, 
                    array( 'column' => 'age_category', 'modifiers' => array(
                        new Null_Validator(), new Unique_Code_Enforcer('age_categories_easy_db_test_temp')))),
                'age_category', FALSE),
            new Single_Column_Output( new Value_Processor($db, $user_config, 
                    array( 'column' => 'date_time', 'modifiers' => array(new Date_Validator_Formatter()))),
                'date_added', FALSE)
        );

        $age_category_processor = new Record_Processor(array('user_config' => $user_config,
            'data_outputs' => $data_outputs,
            'output_table' => 'age_categories_easy_db_test_temp', 'primary_key_column' => 'age_category_id'));
        $test_data = "[\"elderly\", \"5-5-2005\"]";
        $test_data_array = json_decode($test_data, true /* parse into associative arrays*/);
        $age_category_processor->process_row($test_data_array);
        $result = $db->query($age_category_processor->insert_main_record_sql());
        if ( ! $result ) echo 'Error with insert:' . $db->error() . '<br>';

        $result = $db->query("SELECT * from age_categories_easy_db_test_temp where age_category = 'elderly'");
        if ( ! $result ) echo 'Error with insert check:' . $db->error() . '<br>';
        else {
            $this->assertEquals(1, $result->num_rows, "Row was not inserted, or the unique column was not enforced.");
        }
        
        // Now try to insert a record that uses this for entry for a code

        $animal_processor = $this->animal_processor($db, $this->user_config);
        $test_data = "[\"5-5-2005\", \" T \", \"elderly\", \"jimmy\"]";
        $test_data_array = json_decode($test_data, true /* parse into associative arrays*/);
        $animal_processor->process_row($test_data_array);
        $result = $db->query($animal_processor->insert_main_record_sql());
        if ( ! $result ) echo 'Error with insert:' . $db->error . '<br>';

        $result = $db->query("SELECT * from animals_easy_db_test_temp where animal_code = 'jimmy'");
        if ( ! $result ) echo 'Error with insert check:' . $db->error . '<br>';
        else {
            $this->assertEquals(1, $result->num_rows, "Row was not inserted, or the unique column was not enforced.");
        }

        // test duplicate check
        $dup_check = $animal_processor->generate_duplicate_check();
        $result = $db->query($dup_check);
        if ( ! $result ) echo 'Error with duplicate check:' . $db->error . '<br>';
        else {
            $this->assertEquals(1, $result->num_rows, "Duplicate check did not find a matching record.");
        }

        // test unique enforceer
        // re-create the processor so the in memory-cache of code values is refreshed
        $animal_processor = $this->animal_processor($db, $this->user_config);
        try {
            $animal_processor->process_row($test_data_array);
        } catch (Exception $ex) {
            $this->assertEquals(
                "Exception processing value: Code already appears in the 'animals_easy_db_test_temp' table.", 
                $ex->getMessage()); 
        }
    }

    function animal_processor($db, $user_config) {
        $data_outputs = array(
            // date time
            new Single_Column_Output( new Value_Processor($db, $user_config, 
                    array( 'column' => 'birthday', 'modifiers' => array(new Date_Validator_Formatter()))),
                'birthday', FALSE),
            new Single_Column_Output( new Value_Processor($db, $user_config, 
                    array( 'column' => 'is_male', 'modifiers' => array(new Boolean_Validator()))),
                'is_male', FALSE),
            new Single_Column_Output( new Value_Processor($db, $user_config, 
                    array( 'column' => 'age_category_id', 'modifiers' => array(
                        new Null_Validator(), new Code_Value_Validator('age_categories_easy_db_test_temp')))),
                'age_category_id', FALSE),
            new Single_Column_Output( new Value_Processor($db, $user_config, 
                    array( 'column' => 'animal_code', 'modifiers' => array(
                        new Unique_Code_Enforcer('animals_easy_db_test_temp')))),
                'animal_code', FALSE)
        );

        return new Record_Processor(array('user_config' => $user_config, 'data_outputs' => $data_outputs,
            'output_table' => 'animals_easy_db_test_temp', 'primary_key_column' => 'animal_id'));
    }

    function test_repeated_column() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $data_output = new Repeated_Column_Output( array( new Single_Column_Output( 
            new Value_Processor($db, $this->user_config, $processor_config), "time", FALSE)), 3, 'foreign_key_column', 'table', TRUE) ;
        $record_processor = new Record_Processor(array('user_config' => $this->user_config,
        'data_outputs' => array($data_output), 'output_table' => 'unused','primary_key_column' => 'unused'));
        $record_processor->process_row(array("22/3/2012", "22/3/2012", "22/3/2012"));
        $this->assertEquals($record_processor->output_to_array(), array("2012-3-22", "2012-3-22", "2012-3-22"));
    }

    function test_repeated_combiner() {
        $db = 1;
        $val_processors = array();
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $val_processors[] = new Value_Processor($db, $this->user_config, $processor_config);
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Time_Validator_Formatter());
        $val_processors[] = new Value_Processor($db, $this->user_config, $processor_config);
        
        $data_output = new Column_Combiner_Output($val_processors, "date_time", FALSE);

        $data_output = new Repeated_Column_Output( array( $data_output), 2, 'foreign_key_column', 'table', FALSE);
        $record_processor = new Record_Processor(array('user_config' => $this->user_config,
            'data_outputs' => array($data_output), 'output_table' => 'unused','primary_key_column' => 'unused'));

        $record_processor->process_row(array("22/3/2012", "1:20", "12/5/2012", "2:30"));
        $this->assertEquals($record_processor->output_to_array(), array("2012-3-22 1:20", "2012-5-12 2:30"));
    }

    function test_repeated_splitter() {
        $db = 1;
        $val_processors = array();
        $processor_config = $this->default_processor_config;
        $data_output = new Column_Splitter_Output( array(), "split_column", ",", FALSE);
        $data_output = new Repeated_Column_Output( array( $data_output), 2, 'foreign_key_column', 'table', FALSE);
        $record_processor = new Record_Processor(array('user_config' => $this->user_config, 
            'data_outputs' => array($data_output), 'output_table' => 'unused','primary_key_column' => 'unused'));

        $record_processor->process_row(array("val1,val2", "val3,val4"));
        $this->assertEquals(array("val1", "val2", "val3", "val4"), $record_processor->output_to_array() );

    }

    // TODO write test for column splitter
    function test_column_combiner_processor() {
        $db = 1;
        $val_processors = array();
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $val_processors[] = new Value_Processor($db, $this->user_config, $processor_config);
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Time_Validator_Formatter());
        $val_processors[] = new Value_Processor($db, $this->user_config, $processor_config);
        
        $data_output = new Column_Combiner_Output($val_processors, "date_time", FALSE);

        $record_processor = new Record_Processor(array('user_config' => $this->user_config, 
            'data_outputs' => array($data_output), 'output_table' => 'unused','primary_key_column' => 'unused'));
		$record_processor->process_row(array("22/3/2012", "2:30"));
		$outputs = $record_processor->get_outputs();
        $this->assertEquals("2012-3-22 2:30", $outputs[0]->get_last_val());
        $this->assertEquals(array("2012-3-22 2:30"), $record_processor->output_to_array());
    }

    function test_record_processor() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Time_Validator_Formatter());
        $data_output = new Single_Column_Output( new Value_Processor($db, $this->user_config, $processor_config), 
            "time", FALSE);
        $data_output2 = new Single_Column_Output( new Value_Processor($db, $this->user_config, $processor_config),      
            "time2", FALSE);

        $processor_config['modifiers'] = array(new Date_Validator_Formatter());
        $data_output3 = new Single_Column_Output(new Value_Processor($db, $this->user_config, $processor_config),
            'date', FALSE);

        $record_processor = new Record_Processor(array('user_config' => $this->user_config,
            'output_table' => 'unused',
            'primary_key_column' => 'unused',
            'data_outputs' => array($data_output, $data_output2, $data_output3)));
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
		$outputs = $record_processor->get_outputs();
        $this->assertEquals("2:30", $outputs[0]->get_last_val());
        $this->assertEquals("4:30", $outputs[1]->get_last_val());
        $this->assertEquals("2012-3-22", $outputs[2]->get_last_val());
        $this->assertEquals(array("2:30", "4:30", "2012-3-22"), $record_processor->output_to_array());
    }

    function test_code_value_validator(){
        $db = $this->user_config->get_database_connection();

        $result = $db->query("insert into animals_easy_db_test_temp  (animal_code) values ('animal_code')");
        if ( ! $result ) throw new Exception("Error adding test data: " . $db->error);
        $result = $db->query("insert into animals_easy_db_test_temp  (animal_code) values ('animal_code2')");
        if ( ! $result ) throw new Exception("Error adding test data: " . $db->error);
        
        $processor_config = $this->default_processor_config;
        $processor_config['modifiers'] = array(new Code_Value_Validator('animals_easy_db_test_temp'));
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $vp->init("test_record_table");
        // these will print error messages if they fail to find the codes
        $vp->process_value("animal_code");
        $vp->process_value("animal_code2");
    }

    function test_strip_spaces() {
        $db = 1;
        $processor_config = $this->default_processor_config;
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "123", $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::BEFORE;
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "123  ", $vp->process_value("  123  "), "problem stripping whitespace.");

        $processor_config['strip_whitespace'] = Strip_Whitespace::AFTER;
        $vp = new Value_Processor($db, $this->user_config, $processor_config);
        $this->assertEquals( "  123", $vp->process_value("  123  "), "problem stripping whitespace.");
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
            $this->assertEquals( "Error with date formatting.", $ex->getMessage(), "Caught the wrong error.");
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
