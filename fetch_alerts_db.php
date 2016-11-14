#!/usr/bin/php -f
<?php
$lastFetch = -1;

// work in UTC only
date_default_timezone_set("UTC");

function fetch_alerts($dbh)
{
	$alertsString = file("http://sotawatch.org/alerts.php");

	$alert = array();
	$tmp = array();
	$dt = 0;
	$state = "NEW";

	foreach ($alertsString as $alertLine)
	{
		$alertLine = trim($alertLine);
		if ($state == "NEW" && strpos($alertLine, "class=\"alertDate") !== FALSE)
		{
			// update the date
			$dStr = substr($alertLine, strpos($alertLine, "<span class")+24);
			$dStr = substr($dStr, 0, strpos($dStr, "<"));
			$dt = $dStr;
		}
		else if ($state == "NEW" && strpos($alertLine, 
					"td width = \"70px\">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;") !== FALSE)
		{
			$tm = trim(substr($alertLine, 49));
			$tm = str_replace("</td>", "", $tm);
			$alert['time'] = strtotime("$dt $tm");
			$alert['startTime'] = $alert['time']-3600;
			$alert['endTime'] = $alert['time']+10800;
			$state = "OP";
		}
		else if ($state == "OP" && strpos($alertLine, "<strong>") === 0)
		{
			$op = substr($alertLine, 8);
			$op = str_replace("</strong> on", "", $op);
			$alert['op'] = $op;
			$state = "SUMMIT";
		}
		else if ($state == "SUMMIT" && strpos($alertLine, "summits.php") !== 0)
		{
			$summit = str_replace("<a target=\"_blank\" href =\"http://www.sota.org.uk/Summit/", "", $alertLine);
			$summit = substr($summit, 0, strpos($summit, "\""));
			
			$alert['summit'] = $summit;
			$state = "COMMENT";
		}
		else if ($state == "COMMENT" && strpos($alertLine, "span class=\"comment\"") !== FALSE)
		{
			$comment = str_replace("<span class=\"comment\">", "", $alertLine);
		        $comment = substr($comment, 0, strpos($comment, "</span>"));	
			$alert['comment'] = $comment;	

			if (strpos($comment, "S-") !== FALSE ||
			    strpos($comment, "S+") !== FALSE ||
				 strpos($comment, "s-") !== FALSE ||
				 strpos($comment, "s+") !== FALSE)
			{
				//
				$time = $alert['time'];
				$startPattern = array();
				$endPattern = array();
				$fndS = preg_match("/[sS]-([0-9]+)/", $comment, $startPattern);
				$fndE = preg_match("/[sS]\+([0-9]+)/", $comment, $endPattern);

				print_r($startPattern);
				print_r($endPattern);

				if ($fndS)
				{
					$shift = 3600*$startPattern[1];
					print "$time $shift\n";
					unset($alert['startTime']);
					$alert['startTime'] = $time-$shift;
					print $alert['startTime'] . "\n";
				}

				if ($fndE == 1)
				{
					$shift = 3600*$endPattern[1];
					print "$time $shift\n";
					unset($alert['endTime']);
					$alert['endTime'] = $time+$shift;
				}
			}
			
			// check we're not excluded, and add if not
			if (strpos($comment, "RBNN") === FALSE && strpos($comment, "NoRBNGate") === FALSE && strpos($comment, "NoRBNHole") === FALSE)
			{
				$sql = "select op from ExcludedActivators where op='" . $alert['op'] . "';";
				$res = $dbh->query($sql) or print $dbh->error;
				if ($res->num_rows == 0)
					array_push($tmp, $alert);
			}
			$state = "NEW";
		}

	}

	$lastFetch = time();

	return $tmp;
}

include_once('/path/to/rbnhole/db.inc');
$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

$dbh->autocommit(false);

$alerts = fetch_alerts($dbh);
print_r($alerts);

if (count($alerts) != 0)
{
   $dbh->query("truncate table alerts") or die($dbh->error);
}

foreach ($alerts as $alert)
{

	$sql = "insert into alerts(startTime, endTime, op, summit, comment, time) values(FROM_UNIXTIME('" . 
          ($alert['startTime']) . "'), FROM_UNIXTIME('" . ($alert['endTime']) . "'), '" . $alert['op'] . "','" . $alert['summit'] . "', '" . $alert['comment'] . "', FROM_UNIXTIME('" . $alert['time'] . "'));";

  
   $dbh->query($sql) or print $dbh->error;
   //print ($alert['time']-7200) . " " . ($alert['time']+7200) . " " . $alert['op'] . " " . $alert['summit'] . "\n";
}
$dbh->commit();
$dbh->close();
// vim: sw=3 ts=3 cindent
?>
