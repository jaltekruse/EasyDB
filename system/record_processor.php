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

    // Map from ouput name to numerical index of output in the data_outputs array.
    // This allows for referring to outputs by name, without relying on the technically undefined
    // ordering or elements within an associative array. Thus we can loop through the outputs for
    // processing data that is comming in a specific order (without moving the data into an associative
    // array beforehand) but after processing the data outputs can be referred to by their names
    // for doing cross column valiation without leaving another place in the code to break when the column
    // orderings are changed
    private $data_output_names;

    // to allow for efficient processing into sql queries, additional references to the data
    // outputs are stored on creation of this Record_Processor
    private $repeated_outputs;
    private $non_repeated_outputs;

    // the system allows for metadata stored outside of the sheet to be added to each record
    // this data only needs to be validated once, so here the columns and data to be added to the
    // insert statements are stored for concatination into the sql queries
    private $sheet_external_fields_and_data;
    
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
        if (isset($parameters['data_output_names']))
            $this->data_output_names = $parameters['data_output_names'];
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

    function set_sheet_external_fields_and_data($sheet_external_fields_and_data) {
        $this->sheet_external_fields_and_data = $sheet_external_fields_and_data;
    }

    private function start_row($row) {
        $this->last_input_row = $row;
    }

    function process_row_assoc($row) {
        $this->start_row($row);
        $last_input_row_errored = FALSE;
        $curr_data_output = NULL;
        foreach ($row as $field => $value) {
            if ( ! ( isset($this->data_output_names[$field]) &&
                isset($this->data_outputs[$this->data_output_names[$field]] ) ) ) {
                // the input contained a field not matching a data output
                // for now ignore it, possibly design a system for warnings
                continue;
            }
            $curr_data_output = $this->data_outputs[$this->data_output_names[$field]];
            $curr_data_output->reset_for_new_row();
            if ( ! is_array($value) ) {
                // TODO - decide what to do here
                // this check is here to handle cases where a user mistakenly passes data associated
                // with a data output designed not to take anything, might want to just ignore it instead
                // of throwing the error
                if ($curr_data_output->can_take_more_input())
                    $curr_data_output->convert_to_output_format($value);
                else {
                    $last_input_row_errored = TRUE;
                    $this->place_error_char($i);
                    throw new Exception("Input not expected for '" . $field . "', but some was provided."); 
                }
            }
            else {
                foreach ($value as $parts) {
                    if ($curr_data_output->can_take_more_input()) {
                        $curr_data_output->convert_to_output_format($value);
                    } else {
                        $last_input_row_errored = TRUE;
                        $this->place_error_char($i);
                        throw new Exception("Too much input provided for '" . $field . "'.");
                    }
                }
            }
        }
    }

    function process_row($row) {
        $this->start_row($row);
        $this->data_outputs[0]->reset_for_new_row();
        $too_much_input = FALSE;
        $index = 0;
        $row_len = count($row);
        for ($i = 0; $i < $row_len; $i++){
            $value = $row[$i];
            //echo $value . '<br>';
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
                $this->place_error_char($i);
                throw new Exception("Exception processing value: " . $ex->getMessage());
            }
        }
        if ($too_much_input !== FALSE) {
            $last_input_row_errored = TRUE;
            $this->place_error_char($i);
            return;
            // TODO - re-enable this
            //throw new Exception("Unexpected extra input at the end of row, starting at '" . $too_much_input . "'");
        }
        // check to make sure we recieved all the input we expected
        if ($this->data_outputs[$index]->expecting_more_input()){
            $last_input_row_errored = TRUE;
            $this->place_error_char($i);
            throw new Exception("Not all expected values were provided.");
        }
    }

    private function place_error_char($index) {
        $this->last_input_row[$index] = rtrim($this->last_input_row[$index]) . $this->error_char;
    }

    /*
     * this is used by the instances of record processor that are handling sheet external data
     * they do not generate entire sql statements, but instead column and values lists as strings
     * to be injected into other records.
     *
     * TODO - think about separating the public interfaces for sheet processors and external
     *        data processors, possibly have them inherit from a this common subclass with
     *        common functionality declared private or protected. For each subclass different
     *        methods that need to be public can be wrapped in public methods.
     */
    function generate_columns_and_data_lists() {
        $output_array = $this->output_to_assoc_array();
        $lists = array();
        // I know array_keys and array_valus exist, but I could not find a definite answer if they are
        // both guarenteed to return values in the same order
        foreach ( $output_array as $field => $value) {
            $lists['fields'][] = $field;
            $lists['data'][] = $value;
        }
        $lists['fields'] = "`" . implode('`, `', $lists['fields']) . "`";
        $lists['data'] = implode(', ', $lists['data']);
        return $lists;
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
        if (! $result) {
            echo $insert_sql . '<br>';
            echo "Error with main record insert:" . $db->error . "<br>\n";
        }
        $main_record_id = $db->insert_id;
        $child_insert_sql = $this->insert_child_record_sql($main_record_id);
        foreach ($child_insert_sql as $sql){
            $result = $db->query($sql);
            if (! $result)
                echo "error with child record insertion:" . $db->error . "<br>\n";
        }
        return $main_record_id;
    }

    function insert_main_record_sql(){
        $sql_statements = array();
        $values = array();
        foreach ($this->non_repeated_outputs as $data_output) {
            $data_output->add_values_to_assoc_array($values);
        }
        return Record_Processor::insert_sql_based_on_assoc_array($values, $this->output_table, 
            $this->sheet_external_fields_and_data);
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

    public static function insert_sql_based_on_assoc_array($values, $table, $external_fields_and_data = NULL) {
        $quoted_vals = array();
        $external_fields = "";
        $external_data = "";
        if ($external_fields_and_data != NULL) {
            $external_fields = ", " . $external_fields_and_data['fields'];
            $external_data = ", " . $external_fields_and_data['data'];
        }
        foreach ($values as $key=>$val) {
            if (is_null($val))
                $quoted_vals[$key] = "NULL";
            else
                $quoted_vals[$key] = "'" . $val . "'";
        }
        $sql = "insert into " . $table . " (`" . 
            implode("`,`", array_keys($values)) . "`" . $external_fields . ") VALUES ";
        $sql .= "(" . implode(",", $quoted_vals) . $external_data . ")";
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
