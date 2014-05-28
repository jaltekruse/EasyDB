<?php

class Sheet_Processor { 

    private $record_processor;
    // This processor will validate data that does not come from the 'cells' of your
    // primary dataset. It can represent metata like shet filename, uploader, author
    // of the record or the date of upload
    private $external_vals_processor;

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
            $$this->current_time = $row['time'];
        }
        else{
            // database conntction error, should have been reported earlier
        }
        // TODO - validate and generate sheet external data insert statement fragments
        // external columns are assumed to be in an associative array, as they are not part of a sheet
        // we will not want them to have to come in a specific order
        $this->external_vals_processor->process_row_assoc($external_columns);
        $this->record_processor->set_sheet_external_fields_and_data(
            $this->external_vals_processor->generate_columns_and_data_lists());
        $lines = explode($data, $this->row_separator);
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
            }
        }

        for ($i = $lines_to_skip; $i < $line_count; $i++) {
            $line = $lines[$i];
			// ignore blank lines
            if (trim($line) == "") continue;

            $row = explode("\t", $line);
            if ($handling_resubmitted_records) {
                $record_id = trim(array_shift($row));
            }

			// save this line in the history of uploads, unlike before I am saving all uploaded raw data as well as
            // application output
			$record_id = save_upload_attempt_in_history($entry_parts, '', '0', $record_id, $external_columns);	
			if ($record_id == false){
				continue; // there was an issue with the record_id
            }

            try {
                $record_processor->process_row($row);
            } catch (Exception $ex) {
                echo "Error processing row - " . $ex->getMessage();
                $to_save = $record_processor->get_last_input_row();
                // add back the record id
                array_unshift($to_save, $record_id);
	            save_upload_attempt_in_history($to_save, 'error', '1', $record_id, $external_columns);
                continue;
            }
            $dup_check = $record_processor->generate_duplicate_check();
            $result = $db->query($dup_check);
            if ( ! $result ) $dev_err .=  "ERROR WITH DUP CHECK!! : " . $db->error;
            else {
                if ($result->num_rows > 0) {
                    save_record($original_entry_parts, 'duplicate', '1', $record_id);
                    continue;
                }     
            }
            $record_inserter->process_row($entry_parts);
            print_r($record_inserter->output_to_array);
            $new_observation_id = $record_inserter->insert();
            $result = $db->query("update scan_observations set record_id = '" . $record_id . "' where observation_id = '" . $new_observation_id . "'");	
            if ( $result ){ } // success
            else{
                echo "Error linking uploaded record to upload history: " .  $db->error . "<br>";
            }
            // need to allow changes in some of the columns to impact the actual row data that will be saved
            // after processing, such as with columns that are supposed to fill down the sheet, but we will not
            // necessarily be processing the record above it the provided the values when we are going to correct
            // a record in error at a later time (as the preivous records may have been without errors)
            save_record($original_entry_parts, 'successful add', '1', $record_id);
        } 
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

    // TODO - deal with invalid record numbers passed in
    // assumes that record_fields has 
    function save_upload_attempt_in_history($input, $status, $is_webapp_output, $record_id, $record_fields){
        $uploader_id = $record_fields['uploader_id'];
        if (is_null($record_id) || ! ctype_digit(trim($record_id))){
            unset($record_fields['uploader_id']);
            $record_insert_query = Record_Processor::insert_sql_based_on_assoc_array($record_fields, 'records');
            $result = $db->query($record_insert_query);	
            if ($result){
                $record_id = $db->insert_id;
            }
            else{
                echo "error adding record to upload history:" . $db->error;
            }
        }
        $sql = "select record_id from records where record_id = '" . $record_id . "'";
        $result = $db->query($sql);
        if ( $result && $result->num_rows == 1){
            ;// successful add of record, or recall of correct previous id
        }
        else{
            $dev_err .= "Could not find record with the given ID:" . $db->error . "<br>\n";
            // TODO - make this a class level variable?
            //$invalid_record_numbers .= $record_id . ", ";
            return false; // do not do the next query, it will fail due to a bad foreign key
        }
        $sql = "INSERT INTO upload_attempts (upload_date, uploader_id, record_id, is_webapp_output, status, ";
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
        $result = $db->query($sql);
        if (! $result){
            echo "Error adding upload attempt: " . $db->error . " - " . $sql;
        }
        return $record_id;
    }
}

?>
