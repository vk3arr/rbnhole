<?php

$string = file_get_contents("https://pskreporter.info/cgi-bin/pskquery5.pl?encap=1&callback=doNothing&statistics=1&noactive=1&nolocator=1&flowStartSeconds=-900&modify=all&senderCallsign=ZZZZZ");

// remove all but doNothing
$string = strstr($string, "{");
$string = substr($string, 0, strpos($string, ");"));

$results = json_decode($string, TRUE);

$validRows = array();

foreach ($results['receptionReport'] as $row)
{
   // pull out FT8 and JT65 spots from the stream
   if ($row['mode'] == "FT8" || $row['mode'] == "JT65")
   {
      $spot = array();
      $spot['op'] = $row['senderCallsign'];
      $spot['dx'] = $row['receiverCallsign'];
      $spot['freq'] = $row['frequency'] / 1000.0;
      $spot['mode'] = $row['mode'];
      $spot['time'] = $row['flowStartSeconds'];

      array_push($validRows, $spot);
   }
}

include_once('/path/to/rbnhole/db.inc');
$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

foreach ($validRows as $spot)
{
   //
   $sql = "insert into psk_spots(dx, op, frequency, mode, time) values('" . 
               $spot['dx'] . "', '" . $spot['op'] . "', '" . $spot['freq'] . 
               "', '" . $spot['mode'] . "', FROM_UNIXTIME(" . $spot['time'] . 
               "))";

   $res = $dbh->query($sql) or print $dbh->error;
}

// vim: sw=3 ts=3 cindent expandtab

?>
