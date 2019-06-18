#!/usr/bin/php -f
<?php

include_once('/path/to/rbnhole/db_3.inc');

$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

$sql = "insert into monitoring(select NOW(), count(*) from rbn_spots)";
$res = $dbh->query($sql) or die("SQL failed\n");
$dbh->close();
// vim: sw=3 ts=3 cindent

?>
