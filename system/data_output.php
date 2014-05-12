<?php
include_once("value_processor.php");

// TODO  enhance these to allow for map to determine input/output columns
abstract class Data_Output {
    
    protected $inputs_handled_count;
 
    function __construct() {
        
    } 
    
    public function reset_for_new_row() {
        $this->inputs_handled_count = 0;
    }   
    
    protected function get_current_index() {
        return $this->inputs_handled_count;
    }
    
    abstract function convert_to_output_format($value); 
    abstract function number_of_inputs();
    abstract function get_last_val();
    
    function finished_handling_an_input(){
        $this->inputs_handled_count++;
    }
    
    function can_take_more_input() {
        if ( $this->inputs_handled_count < $this->number_of_inputs())
            return TRUE;
        else
            return FALSE;
    }

    function add_values_to_array(&$val_list) {
        array_push($val_list, $this->get_last_val());
    }

    // this is going to mostly function the same as the can_take_more_input method
    // it will be overridden for Data_Output instances where multiple outputs are
    // allowed, but not a fixed amount
    function expecting_more_input() {
        return $this->can_take_more_input();
    }
}

class Single_Column_Output extends Data_Output {

    private $output_column_name;
    private $value_processor;
    private $last_val;

    public function get_last_val(){
        return $this->last_val;
    }

    function __construct($value_processor, $output_column_name){
        $this->output_column_name = $output_column_name;
        $this->value_processor = $value_processor;  
        // TODO - pass down table name from above
        $value_processor->init("dummy_table_name_fixme");
    }
    
    function number_of_inputs() { 
        return 1;
    }

    function convert_to_output_format($value) {
        try {
            $this->last_val = $this->value_processor->process_value($value); 

            // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
            $this->finished_handling_an_input();
        } catch (Exception $ex) {
            // TODO - make this report an error to the user and store it in the upload history 
            throw $ex;
            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        } 
    }

}

abstract class Column_Splitter_Output extends Data_Output {

    private $output_column_names;
    private $value_processors;
    private $value_processor_count;
    private $last_vals;

    public function get_last_val(){
        throw new Exception("Operation unsupported for column splitter.)");
    }

    function add_values_to_array(&$val_list) {
        foreach ($this->last_vals as $value ) { 
            array_push($val_list, $value);
        }
    }

    function __construct($value_processors, $output_column_names){
        $this->output_column_names = $output_column_names;
        $this->value_processors = $value_processors;  
        foreach($this->value_processors as $value_processor) {
            // TODO - pass down table name from above
            $value_processor->init("dummy_table_name_fixme");
        }
        $this->value_processor_count = count($this->value_processors);
    }
    
    function number_of_inputs() { 
        return $this->value_processor_count;
    }

    abstract function split_value($value);

    function convert_to_output_format($value) {
        try {
            $curr_vals = $this->split_value($value);
            foreach ( $curr_vals as $split_val ) {
                $this->last_vals[$this->get_current_index()] = $this->value_processors[$this->get_current_index()]->process_value($split_val); 

                // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
                $this->finished_handling_an_input();
            }
        } catch (Exception $ex) {
           // TODO - make this report an error to the user and store it in the upload history 

            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        }
    }

}

class Column_Combiner_Output extends Data_Output {

    private $output_column_name;
    private $value_processors;
    private $value_processor_count;
    private $last_vals;

    public function get_last_val(){
        return implode(" ", $this->last_vals);
    }

    function __construct($value_processors, $output_column_name){
        $this->output_column_name = $output_column_name;
        $this->value_processors = $value_processors;  
        foreach($this->value_processors as $value_processor) {
            // TODO - pass down table name from above
            $value_processor->init("dummy_table_name_fixme");
        }
        $this->value_processor_count = count($this->value_processors);
    }
    
    function number_of_inputs() { 
        return $this->value_processor_count;
    }

    function convert_to_output_format($value) {
        try {
            $this->last_vals[$this->get_current_index()] = $this->value_processors[$this->get_current_index()]->process_value($value); 

            // TODO - finally blocks are only in PHP 5.5+, this is a hackt get around it
            $this->finished_handling_an_input();
        } catch (Exception $ex) {
           // TODO - make this report an error to the user and store it in the upload history 

            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        }
    }
}

?>
