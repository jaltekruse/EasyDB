<?php
global $system_test_user_config_parameters;
$system_test_user_config_parameters = 
	array(
    "database_connections" =>
    	array(
			"read_write" =>
			array(
				"username"    => "mbed_karen", 
				"host"        => "dbhost.ssc.wisc.edu",
				"password"    => "",
				"port"        => "",
				"database"    => "mbed"
			)
    	),
		"code_tables" =>
		array(
            "animals_easy_db_test_temp" =>            array("id_column" => "animal_id",       "code_column" => "animal_code" ),
			"age_categories_easy_db_test_temp" =>     array("id_column" => "age_category_id", "code_column" => "age_category")
		)
	);
?>
