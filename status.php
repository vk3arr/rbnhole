#!/usr/bin/php -f

<?php

include('/home/anryan/hole/db.inc');

$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

$status = array();

$sql = "select no_spot_cnt from watchdog";

$res = $dbh->query($sql) or print $dbh->error;
$row = $res->fetch_row();
$status['watchdog_count'] = $row[0];

if ($row[0] <= 2)
   $status['watchdog_status'] = 'OK';
else
   $status['watchdog_status'] = 'ERROR';

$sql = "select count(*) from rbn_spots";

$res = $dbh->query($sql);
$row = $res->fetch_row();
$status['spots_from_rbn_in_cycle'] = $row[0];

$sql = "select max(time) from PostedSpots";


$res = $dbh->query($sql);
$row = $res->fetch_row();
$status['last_spot_time'] = $row[0];

$json = json_encode($status);

print $json;

?>
