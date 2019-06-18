<?php

function db_connect()
{
	include_once('/path/to/rbnhole/db.inc');

	$dsn = "mysql:charset=UTF8;host=" . $DB_HOST . ";dbname=" . $DB_NAME;

	try
	{
		$dbh = new PDO ($dsn, $DB_USER, $DB_PASS);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
	}
	catch (PDOException $e)
	{
		die($e->getMessage());
	}

	return $dbh;
}


// vim:sw=3 ts=3 cindent noexpandtab
?>

