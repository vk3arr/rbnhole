#!/usr/bin/php -f
<?php

include_once('/path/to/rbnhole/db_2.inc');

$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

$sql = "select count(*) from rbn_spots";
$res = $dbh->query($sql);
$row = $res->fetch_row();

if ($row[0] == 0)
{
   $sql = "update watchdog set no_spot_count = no_spot_count+1";
   $dbh->query($sql);
   return;
}

function fetch_sw_spots()
{
   global $apiUser, $apiKey, $dbh;

   $url = "http://api.sota.org.uk/api/spot/1/hours?userKey=$apiUser&apiKey=$apiKey"
   $json = file_get_contents($url);
   $spots = json_decode($json);
   
   foreach ($spots as $spot)
   {
      if (strtoupper($spot['Mode']) == "CW")
      {
         // check if spot ID already been seen
         $sql = "select * from sw_spots where id='" . $spot['ID'] . "'";
         $res = $dbh->query();
         
         // virgin spot
         if ($res->num_rows == 0)
         {
            // add a spot into SeenActivators
            $op = $spot['ActivatorCallsign'];
            $band = floor($spot['Frequency']);
            $freq = $spot['Frequency'];
            $summit = $spot['AssociationCode'] . "/" . $spot['SummitCode'];
            $id = $spot['Id'];

	         $sql =  "insert into PostedSpots (op, band, freq, summit, time) ";
            $sql .= "values('$op', '$band', '$freq', '$summit', NOW());";
            $dbh->query();

            // then insert into sw_spots
            $sql  = "insert into sw_spots (id, op, freq, time) values ";
            $sql .= "('$id', '$op', '$freq', NOW());";
            $dbh->query();
         }
      }
   }
}

fetch_sw_spots();

$sql = "delete from rbn_spots where op not in(select b.op from alerts b);";
$dbh->query($sql);

$sql = "delete from rbn_spots where TIMESTAMPDIFF(MINUTE, time, NOW()) > 10";
$dbh->query($sql);

//$sql = "select a.op, freq, count(freq) from rbn_spots a, alerts b where a.op = b.op group by freq order by count(freq) desc limit 1;";
//$sql = "select c.op, freq, max(cnt) from (select a.op, freq, count(freq) as cnt from rbn_spots a, alerts b where a.op = b.op group by freq order by count(freq) desc) c;";
$sql = "select op, freq from SeenActivators a where cnt = (select max(cnt) from SeenActivators where op = a.op);";
$res = $dbh->query($sql);
$rows = $res->fetch_all();

if ($res->num_rows == 0)
{
	$dbh->close();
	exit;
}

$handle = curl_init('http://old.sota.org.uk/Spotlite/postSpot');

curl_setopt($handle, CURLOPT_POST, 0);

function post_spot($op, $dx, $snr, $wpm, $freq, $summit)
{
	global $dbh, $handle, $RBNHOLE_USER, $RBNHOLE_PASS;

	$assoc = substr($summit, 0, strpos($summit, "/"));
	$ref = substr($summit, strpos($summit, "/")+1);
	print "$assoc $ref\n";
	$dx = urlencode(substr($dx, 0, strpos($dx, "-")));
	$comment = "%5BRBNHole%5D++at+$dx+$wpm+WPM+$snr+dB+SNR";
	$freq /= 1000.0;  // RBN is in kHz, SOTAWatch in MHz
	$band = floor($freq);
	$upperFreq = $freq + 0.00125;
	$lowerFreq = $freq - 0.00125;
	$sql = "select op from PostedSpots where freq<'$upperFreq' and freq >'$lowerFreq' and op ='$op' and time>SUBDATE(NOW(), SEC_TO_TIME(3600));";
	$res = $dbh->query($sql) or print $dbh->error;

	// already spotted
	if ($res->num_rows > 0)
		return;

	$url = "actCallsign=" . curl_escape($handle, $op) . "&assoc=$assoc&summit=$ref&freq=$freq&mode=cw&comments=$comment&callsign=$RBNHOLE_USER&password=$RBNHOLE_PASS&submit=SPOT%21";

	curl_setopt($handle, CURLOPT_POSTFIELDS, $url);
	print "$url\n";
	curl_exec($handle) or print "cURL failed";

	// do duplicate insert
	$sql = "delete from PostedSpots where op='$op'";
	$dbh->query($sql);
	$sql = "insert into PostedSpots (op, band, freq, summit, time) values('$op', '$band', '$freq', '$summit', NOW());";
	$dbh->query($sql);
}


foreach ($rows as $row)
{
   $sql = "select dx, a.op, freq, snr, wpm, a.time, summit from rbn_spots a, alerts b where a.op = '$row[0]' and a.freq='" . $row[1] ."' and a.op = b.op and startTime <= a.time and a.time <= endTime order by ABS(TIMESTAMPDIFF(MINUTE,b.time,a.time)) asc, snr desc limit 1;";

   $spotRes = $dbh->query($sql) or print $dbh->error;
   $spot = $spotRes->fetch_row();

   if ($spot != NULL)
   {
		//
		post_spot($spot[1], $spot[0], $spot[3], $spot[4], $spot[2], $spot[6]);
		$sql = "delete from rbn_spots where op = '$row[0]'";
		$dbh->query($sql);
	}
}

curl_close($handle);
$dbh->close();
// vim: sw=3 ts=3 cindent

?>
