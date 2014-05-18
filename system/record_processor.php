<?php
include_once("data_output.php");

class Record_Processor {

    private $data_outputs;
    private $data_outputs_count;
    private $output_table;
    private $primary_key_column;
    
    public function get_outputs() {
        return $this->data_outputs;
    }

    /**
      * Takes an associative array for parameters.
      *
      * Fields: 
      * -------
      * data_outputs - list of Data_Output objects
      * TODO - this will simply work by using the positions of the columns and match them with the
      * inputs (taking into account data_outputs that use up several columns). This should be changed
      * to allow the columns to be moved around and passed an associative array (data will also need to 
      * be send associated with column names, and processors will need to know the names of the columns to process) 
      * extra_fields - to be appended after the columns provided in the main records
      *     This can be used to store information about the datasheet, uploader, observer, or anything that can
      *     be applied to the whole sheet to save the time of copying it into each row manually
      */
    function __construct($parameters){
        assert( isset($parameters['data_outputs']), "Must supply data outputs for Record_Processor.");
        assert( isset($parameters['output_table']), "Must supply output table for Record_Processor.");
        assert( isset($parameters['primary_key_column']), "Must supply primary key column for Record_Processor.");
        $this->output_table = $parameters['output_table'];
        $this->data_outputs = $parameters['data_outputs'];
        $this->data_outputs_count = count($this->data_outputs);
        $this->primary_key_column = $parameters['primary_key_column']; 
        foreach ($this->data_outputs as $data_output) {
            $data_output->set_main_output_table($this->output_table);
            $data_output->set_main_table_pk_column($this->primary_key_column);
        }
    }
    
    function process_row($row) {
        $index = 0;
        $this->data_outputs[0]->reset_for_new_row();
        $too_much_input = FALSE;
        foreach($row as $value) {
            try {
                if ( ! $this->data_outputs[$index]->can_take_more_input()) {
                    $index++;
                    if ( $index >= $this->data_outputs_count) { 
                        $too_much_input = $value; break;
                    }
                    $this->data_outputs[$index]->reset_for_new_row();
                }

                $this->data_outputs[$index]->convert_to_output_format($value);
    
            } catch (Exception $ex ) {
                // store error in upload history, add new column to store message about error passed back
                throw new Exception("Exception processing value: " . $ex->getMessage());
            }
        }
        if ($too_much_input !== FALSE) {
            throw new Exception("Unexpected extra input at the end of row, starting at '" . $too_much_input . "'");
        }
        // check to make sure we recieved all the input we expected
        if ($this->data_outputs[$index]->expecting_more_input()){
            throw new Exception("Not all expected values were provided.");
        }
    }

    function generate_duplicate_check() {
        $sql = "select * from " . $this->output_table . ' ';
        $repeated_output_checks = array();
        $standard_column_checks = array();
        foreach ($this->data_outputs as $data_output) {
            if ($data_output instanceof Repeated_Column_Output) {
                $repeated_output_checks[] = $data_output->duplicate_check_sql();
            } else {
                $standard_column_checks[] = $data_output->duplicate_check_sql();
            }
        }
        $sql .= implode(' ', $repeated_output_checks);
        $sql .= implode(' AND ', $standard_column_checks);
        return $sql;
    }

    function insert_record(){
        
    }

    function output_to_array() {
        $output = array();
        foreach ($this->data_outputs as $data_output) {
            $data_output->add_values_to_array($output);
        }
        return $output;
    }
}

?>
