#!/usr/bin/php -f
<?php

include_once('/path/to/rbnhole/db_2.inc');

$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

function fetch_sw_spots()
{
   global $apiUser, $apiKey, $dbh;

   $url = "http://api.sota.org.uk/api/spot/1/hours?userKey=$apiUser&apiKey=$apiKey";
   $json = file_get_contents($url);
   $spots = json_decode($json, TRUE);
   
   foreach ($spots as $spot)
   {
      if (strtoupper($spot['Mode']) == "CW")
      {
         // check if spot ID already been seen
         $sql = "select * from sw_spots where id='" . $spot['Id'] . "'";
         $res = $dbh->query($sql);
         
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
            $dbh->query($sql);

            // then insert into sw_spots
            $sql  = "insert into sw_spots (id, op, freq, time) values ";
            $sql .= "('$id', '$op', '$freq', NOW());";
            $dbh->query($sql);
         }
      }
   }
}

// rely on the RBN Hole fetch_sw_spots() call to do this.  This is otherwise racy.
//fetch_sw_spots();

$sql = "delete from psk_spots where op not in(select b.op from alerts b);";
$dbh->query($sql);

$sql = "delete from psk_spots where TIMESTAMPDIFF(MINUTE, time, NOW()) > 10";
$dbh->query($sql);

$sql = "select op, freq from SeenPSKActivators a where cnt = (select max(cnt) from SeenPSKActivators where op = a.op);";
$res = $dbh->query($sql);
$rows = $res->fetch_all();

if ($res->num_rows == 0)
{
   $dbh->close();
   exit;
}

$handle = curl_init('http://old.sota.org.uk/Spotlite/postSpot');

curl_setopt($handle, CURLOPT_POST, 0);

function post_spot($op, $dx, $mode, $freq, $summit)
{
   global $dbh, $handle, $RBNHOLE_USER, $RBNHOLE_PASS;

   $assoc = substr($summit, 0, strpos($summit, "/"));
   $ref = substr($summit, strpos($summit, "/")+1);
   print "$summit $op $freq\n";
   //$dx = urlencode(substr($dx, 0, strpos($dx, "-")));
   $dx = urlencode($dx);
   $comment = "%5BRBNHole%5D+heard+at+$dx+%5BDigital+Mode+$mode%5D";
   $freq /= 1000.0;  // RBN is in kHz, SOTAWatch in MHz
   $band = floor($freq);
   $upperFreq = $freq + 0.00125;
   $lowerFreq = $freq - 0.00125;
   $sql = "select op from PostedSpots where freq<'$upperFreq' and freq >'$lowerFreq' and op ='$op' and time>SUBDATE(NOW(), SEC_TO_TIME(3600));";
   $res = $dbh->query($sql) or print $dbh->error;

   // already spotted
   if ($res->num_rows > 0)
   {
      print " - No spot for $op due to spot lockout\n";
      return;
   }

   $url = "actCallsign=" . curl_escape($handle, $op) . "&assoc=$assoc&summit=$ref&freq=$freq&mode=data&comments=$comment&callsign=$RBNHOLE_USER&password=$RBNHOLE_PASS&submit=SPOT%21";

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
   $sql = "select dx, a.op, frequency, snr, wpm, a.time, summit from psk_spots a, alerts b where a.op = '$row[0]' and a.frequency='" . $row[1] ."' and a.op = b.op and startTime <= a.time and a.time <= endTime order by ABS(TIMESTAMPDIFF(MINUTE,b.time,a.time)) asc, snr desc limit 1;";

   $spotRes = $dbh->query($sql) or print $dbh->error;
   $spot = $spotRes->fetch_row();

   if ($spot != NULL)
   {
      //
      post_spot($spot[1], $spot[0], $spot[3], $spot[4], $spot[2], $spot[6]);
      $sql = "delete from psk_spots where op = '$row[0]'";
      $dbh->query($sql);
   }
}

curl_close($handle);
$dbh->close();
// vim: sw=3 ts=3 cindent

?>
