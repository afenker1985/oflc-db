<?php 

	$csv = array_map(function ($line) {
	    return str_getcsv($line, ',', '"', '\\');
	}, file(__DIR__ . '/../config/db.csv'));

	$header = array_shift($csv); 
	$data = array_combine($header, $csv[0]);  
	
	$dsn = "mysql:host={$data['host']};dbname={$data['dbname']};charset=utf8mb4"; 
	
	try {
		$pdo = new PDO($dsn, $data['user'], $data['password'], [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     
		]); 
	} catch (PDOException $e) {     
		die("Connection failed: " . $e->getMessage()); 
}

?>