<?php
include_once("data_output.php");

function assert_true($cond, $message = "No message specified."){
	if ( ! $cond ) {
		assert(FALSE);
		echo $message;
	}	
}
class Record_Processor {

    // defines the sequence of outputs to be used, current interface uses numerical positions
    // of the columns in the input data set. Will be adding interface later to allow matching
    // column names to different value processors (which are nested in data outputs)
    private $data_outputs;

    // to allow for efficient processing into sql queries, additional references to the data
    // outputs are stored on creation of this Record_Processor
    private $repeated_outputs;
    private $non_repeated_outputs;
    
    private $data_outputs_count;
    private $output_table;
    private $primary_key_column;
    private $user_config;
    private $insert_db;
    private $last_input_row;
    private $last_input_row_errored;
    private $error_char;
    
    public function get_outputs() {
        return $this->data_outputs;
    }

    public function get_last_input_row_errored() {
        return $this->last_input_row_errored;
    }

    public function get_last_input_row() {
        return $this->last_input_row;
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
        assert_true( isset($parameters['data_outputs']), "Must supply data outputs for Record_Processor.");
        assert_true( isset($parameters['output_table']), "Must supply output table for Record_Processor.");
        assert_true( isset($parameters['primary_key_column']), "Must supply primary key column for Record_Processor.");
        assert_true( isset($parameters['user_config']), "Must supply user config for Record_Processor.");
        // TODO - make this configurable
        $this->error_char = "*";
        $this->user_config = $parameters['user_config'];
        $this->output_table = $parameters['output_table'];
        $this->data_outputs = $parameters['data_outputs'];
        $this->data_outputs_count = count($this->data_outputs);
        $this->primary_key_column = $parameters['primary_key_column']; 
        $this->repeated_outputs = array();
        $this->non_repeated_outputs = array();
        $this->insert_db = $this->user_config->get_database_connection('read_write');
        foreach ($this->data_outputs as $data_output) {
            $data_output->set_main_output_table($this->output_table);
            $data_output->set_main_table_pk_column($this->primary_key_column);
            if ($data_output instanceof Repeated_Column_Output) {
                $this->repeated_outputs[] = $data_output; 
            }
            else {
                $this->non_repeated_outputs[] = $data_output;
            }
        }
    }
    
    function process_row($row) {
        $last_input_row_errored = FALSE;
        $this->last_input_row = $row;
        $index = 0;
        $this->data_outputs[0]->reset_for_new_row();
        $too_much_input = FALSE;
        $row_len = count($row);
        for ($i = 0; $i < $row_len; $i++){
            $value = $row[$i];
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
                $last_input_row_errored = TRUE;
                $this->last_input_row[$i] .= $this->error_char;
                throw new Exception("Exception processing value: " . $ex->getMessage());
            }
        }
        if ($too_much_input !== FALSE) {
            $last_input_row_errored = TRUE;
            $this->last_input_row[$i] .= $this->error_char;
            throw new Exception("Unexpected extra input at the end of row, starting at '" . $too_much_input . "'");
        }
        // check to make sure we recieved all the input we expected
        if ($this->data_outputs[$index]->expecting_more_input()){
            $last_input_row_errored = TRUE;
            $this->last_input_row[$i] .= $this->error_char;
            throw new Exception("Not all expected values were provided.");
        }
    }

    //=============================================
    // TODO - PREPARED STATEMENTS
    // ============================================
    function generate_duplicate_check() {
        $sql = "select * from " . $this->output_table . ' ';
        $repeated_output_checks = array();
        $standard_column_checks = array();
        foreach ($this->data_outputs as $data_output) {
            if ($data_output instanceof Repeated_Column_Output) {
                $repeated_output_checks[] = $data_output->duplicate_check_sql(NULL);
            } else {
                // passing table name to get rid of 'column ambiguity' errors
                if ( ! $data_output->ignore_in_duplicate_check()) {
                    $standard_column_checks[] = $data_output->duplicate_check_sql($this->output_table);
                }
            }
        }
        $sql .= implode(' ', $repeated_output_checks);
        $sql .= ' WHERE ' . implode(' AND ', $standard_column_checks);
        return $sql;
    }

    function insert() {
        $db = $this->insert_db;
        $insert_sql = $this->insert_main_record_sql();
        $result = $db->query($insert_sql);
        if (! $result)
            echo $db->error . '<br>\n';
        $main_record_id = $db->insert_id;
        $child_insert_sql = $this->insert_child_record_sql($main_record_id);
        foreach ($child_insert_sql as $sql){
            $result = $db->query($sql);
            if (! $result)
                echo $db->error . "<br>\n";
        }
        return $main_record_id;
    }

    function insert_main_record_sql(){
        $sql_statements = array();
        $values = array();
        foreach ($this->non_repeated_outputs as $data_output) {
            $data_output->add_values_to_assoc_array($values);
        }
        return Record_Processor::insert_sql_based_on_assoc_array($values, $this->output_table);
    }

    /*
     * Get the SQL required to insert the current record. The return of the function is
     * an array of statements to execute, one for the main record and another for each
     * of the child rows to insert.
     */
    function insert_child_record_sql($last_insert_id){
        $sql_statements = array();
        foreach ($this->repeated_outputs as $data_output) {
            $sql_statements = array_merge($sql_statements, $data_output->generate_insert_sql($last_insert_id));
        }
        return $sql_statements;
    }

    public static function insert_sql_based_on_assoc_array($values, $table) {
        $quoted_vals = array();
        foreach ($values as $key=>$val) {
            if (is_null($val))
                $quoted_vals[$key] = "NULL";
            else
                $quoted_vals[$key] = "'" . $val . "'";
        }
        $sql = "insert into " . $table . " (`" . implode("`,`", array_keys($values)) . "`) VALUES ";
        $sql .= "(" . implode(",", $quoted_vals) . ")";
        return $sql;
    }

    function output_to_array() {
        $output = array();
        foreach ($this->data_outputs as $data_output) {
            $data_output->add_values_to_array($output);
        }
        return $output;
    }

    function output_to_assoc_array(){
        $output = array();
        foreach ($this->data_outputs as $data_output) {
            $data_output->add_values_to_assoc_array($output);
        }
        return $output;
    }
}

?>
