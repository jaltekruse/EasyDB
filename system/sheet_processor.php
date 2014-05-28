<?php
include_once("record_processor.php");

class Sheet_Processor { 

    protected $record_processor;
    // This processor will validate data that does not come from the 'cells' of your
    // primary dataset. It can represent metata like shet filename, uploader, author
    // of the record or the date of upload
    protected $external_vals_processor;

    // for delimited text such as CSV
    private $row_separator;
    private $column_separtor;
    
    private $skip_blank_lines;
    private $record_id_column_header;

    private $record_table;
    private $upload_attempt_table;
    private $current_time;
    private $db;

    function __construct($db, $record_processor, $external_vals_processor) {
        $this->record_processor = $record_processor;
        $this->external_vals_processor = $external_vals_processor;
        $this->db = $db;
        $this->row_separator = "\n";
        $this->column_separator = "\t";
        $this->skip_blank_lines = TRUE;
        $this->record_id_column_header = "easydb_record_id";
        $this->record_table = 'records';
        $this->upload_attempt_table = 'upload_attempts';
    }

    function check_for_full_sheet_resubmission($data, $external_columns) {
        
    }

    /*
     * This method allows for users to detect if the current row representats the headers for the sheet
     * and handle them accordingly. For some users the sheets may take several formats from changing requirements
     * over the time of their data tracking. This allows them to set specific read rules based on their headers.
     *
     * This method should return true or false if the headers are detected, this prompts the reading of regular
     * data.
     */
    function check_for_column_headers($row) {
        return false;
    }

    function get_last_insert_time() {
        return $this->current_time;
    }

    /*
     * Function to handle input in the form of delimited text with some other fields
     * outside of the main 'sheet'.
     *
     */
    function validate_and_insert_records($data, $external_columns) {
        // this time is used as the upload time for all records processed in this input dataset
        // this allows for gathering data that was uploaded at the same time, which would be less
        // precise if the queries for each insertion used a call to the sql NOW() function
        $result = $this->db->query("SELECT NOW() as time");
        $this->current_time = "";
        if ( $result){
            $row = $result->fetch_assoc();
            $this->current_time = $row['time'];
        }
        else{
            throw new Exception("Error finding current time to store with uploads.");
        }
        $external_columns['upload_date'] = $this->current_time;
        // TODO - validate and generate sheet external data insert statement fragments
        // external columns are assumed to be in an associative array, as they are not part of a sheet
        // we will not want them to have to come in a specific order
        $this->external_vals_processor->process_row_assoc($external_columns);
        $this->record_processor->set_sheet_external_fields_and_data(
            $this->external_vals_processor->generate_columns_and_data_lists());
        echo $data;
        // upload history also uses external columns, but does not need the upload_date in the array
        unset($external_columns['upload_date']);
        $lines = explode( $this->row_separator, $data);
        print_r($lines);
        $handling_resubmitted_records = FALSE;
        // keep track of the number of lines that should be skipped at the beginning of the sheet
        // including blanks and the header
        $lines_to_skip = 0;
        // read the input line by line
        foreach ($lines as $line) {
            $lines_to_skip++;
            if (trim($line) == "" && $this->skip_blank_lines)
                continue;
            $row = explode($this->column_separator, $line);

            // check if the column header for reading re-sumbitted data was reached, the column
            // name chosen for the header of the records id column should not appear in the dataset!
            // especially in the first column as this will corrupt the first line of input
            if ($row[0] == $this->record_id_column_header) {
                $handling_resubmitted_records = TRUE;
                // remove the column to allow the user defined header search to avoid
                // having to work around it
                array_unshift($row);
            }

            // The first column that is not blank is considered either the headers or the first
            // column of input data to be read with the default settings
            // This method should be overridden by users to allow them to set read rules based
            // on the column headers
            if ( ! $this->check_for_column_headers($row) ) {
                $lines_to_skip--;
                break;
            }
        }

        // this method is used to add columns that are needed to process the records if they are re-submitted,
        // but are not stored as part of the records themselves. Currenty this is just used to add the repetition
        // counts for repeated columns. This call is placed here so the sheet processors can use the 
        // check_for_column_headers function to detect the sheet heades and adjust the column counts an necessary.
        // TODO - figure out if the way this works could be cleaner
        // To do this the information must be stored in the ubclass between the two method calls for now, this may
        // change in the future
        $this->add_sheet_processing_metadata($external_columns);
        $line_count = count($lines);
        echo $line_count . ' ' . $lines_to_skip;
        for ($i = $lines_to_skip; $i < $line_count; $i++) {
            $record_id = '';
            $line = $lines[$i];
            echo 'proess line<br>' . $line;
			// ignore blank lines
            if (trim($line) == "") continue;

            $row = explode("\t", $line);
            if ($handling_resubmitted_records) {
                $record_id = trim(array_shift($row));
            }

			// save this line in the history of uploads, unlike before I am saving all uploaded raw data as well as
            // application output
			$record_id = $this->save_upload_attempt_in_history($row, '', '0', $record_id, $external_columns);	
			if ($record_id == false){
				continue; // there was an issue with the record_id
            }

            try {
                $this->record_processor->process_row($row);
            } catch (Exception $ex) {
                echo "Error processing row - " . $ex->getMessage();
                $to_save = $this->record_processor->get_last_input_row();
                // add back the record id
                array_unshift($to_save, $record_id);
	            $this->save_upload_attempt_in_history($to_save, 'error', '1', $record_id, $external_columns);
                continue;
            }
            $dup_check = $this->record_processor->generate_duplicate_check();
            $result = $this->db->query($dup_check);
            if ( ! $result ) $dev_err .=  "ERROR WITH DUP CHECK!! : " . $this->db->error;
            else {
                if ($result->num_rows > 0) {
                    $this->save_upload_attempt_in_history($this->record_processor->get_last_input_row(), 'duplicate', '1', $record_id, $external_columns);
                    continue;
                }     
            }
            $this->record_processor->process_row($row);
            print_r($this->record_processor->output_to_array());
            $new_observation_id = $this->record_processor->insert();
            $result = $this->db->query("update scan_observations set record_id = '" . $record_id . "' where observation_id = '" . $new_observation_id . "'");	
            if ( $result ){ } // success
            else{
                echo "Error linking uploaded record to upload history: " .  $this->db->error . "<br>";
            }
            // need to allow changes in some of the columns to impact the actual row data that will be saved
            // after processing, such as with columns that are supposed to fill down the sheet, but we will not
            // necessarily be processing the record above it the provided the values when we are going to correct
            // a record in error at a later time (as the preivous records may have been without errors)
            $this->save_upload_attempt_in_history($this->record_processor->get_last_input_row(), 'successful add', '1', $record_id, $external_columns);
        } 
    }

    protected function add_sheet_processing_metadata(&$external_columns){
    }

    // TODO - deal with invalid record numbers passed in
    // assumes that record_fields has 
    function save_upload_attempt_in_history($input, $status, $is_webapp_output, $record_id, $record_fields){
        $uploader_id = $record_fields['uploader_id'];
        print_r($record_fields);
        if (is_null($record_id) || ! ctype_digit(trim($record_id))){
            unset($record_fields['uploader_id']);
            $record_insert_query = Record_Processor::insert_sql_based_on_assoc_array($record_fields, 'records');
            $result = $this->db->query($record_insert_query);	
            if ($result){
                $record_id = $this->db->insert_id;
            }
            else{
                echo "error adding record to upload history:" . $this->db->error;
            }
        }
        $sql = "select record_id from records where record_id = '" . $record_id . "'";
        $result = $this->db->query($sql);
        if ( $result && $result->num_rows == 1){
            ;// successful add of record, or recall of correct previous id
        }
        else{
            $dev_err .= "Could not find record with the given ID:" . $this->db->error . "<br>\n";
            // TODO - make this a class level variable?
            //$invalid_record_numbers .= $record_id . ", ";
            return false; // do not do the next query, it will fail due to a bad foreign key
        }
        $sql = "INSERT INTO upload_attempts (upload_date, uploader_id, record_id, is_webapp_output, status, ";
        print_r($input);
        for($i = 1; $i <= count($input); $i++){
            $sql .= "col_" . $i;
            if ( $i != count($input))
                $sql .= ",";
        }
        $sql .= ") values (";
        $sql .= "'" . $this->current_time . "', '" . $uploader_id . "', '". $record_id . "','" . 
            $is_webapp_output . "','" . $status . "','";
        echo "save record<br>";
        print_r($input);
        $sql .= implode("','", $input) . "')"; 
        //echo $sql . "<br>";
        $result = $this->db->query($sql);
        if (! $result){
            echo "Error adding upload attempt: " . $this->db->error . " - " . $sql;
        }
        return $record_id;
    }
}

?>
