#!/usr/bin/php -f
<?php

date_default_timezone_set("UTC");

function fetch_alerts($dbh)
{
	$alerts = json_decode(file_get_contents("https://api2.sota.org.uk/api/alerts/24/"), TRUE);

	$tmp = array();

	foreach($alerts as $alertLine)
	{
		print_r($alertLine);
		$alert = array();
		$time = DateTime::createFromFormat("Y-m-d\TH:i:s", $alertLine['dateActivated']);
		$alert['op'] = $alertLine['activatingCallsign'];
		$alert['time'] = $time->format("U");
		$alert['startTime'] = $alert['time']-3600;
		$alert['endTime'] = $alert['time']+10800;
		$alert['summit'] = $alertLine['associationCode'] . '/' . $alertLine['summitCode'];
		$alert['comment'] = $alertLine['comments'];

		$comment = $alert['comment'];

		if (strpos($comment, "S-") !== FALSE && strpos($comment, "S+") !== FALSE)
		{
			//
			$time = $alert['time'];
			$startPattern = array();
			$endPattern = array();
			$fndS = preg_match("/S-([0-9]+)/", $comment, $startPattern);
			$fndE = preg_match("/S\+([0-9]+)/", $comment, $endPattern);

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
		if (strpos($comment, "RBNN") === FALSE && 
				strpos($comment, "NoRBNGate") === FALSE && 
				strpos($comment, "NoRBNHole") === FALSE)
		{
			$sql = "select op from ExcludedActivators where op=:op";
			$stmt = $dbh->prepare($sql);
			$stmt->bindValue('op', $alertLine['posterCallsign'], PDO::PARAM_STR);
			$stmt->execute();
			$res = $stmt->fetchAll();

			if (count($res) == 0)
				array_push($tmp, $alert);
		}
	}
	return $tmp;
}

include_once('/home/anryan/hole/db_connect.inc');
$dbh = db_connect();

$dbh->beginTransaction();

$alerts = fetch_alerts($dbh);
print_r($alerts);

if (count($alerts) != 0)
{
	$dbh->query("truncate table alerts") or die($dbh->rollback());
}

$sql = "insert into alerts(startTime, endTime, op, summit, comment, time) values(FROM_UNIXTIME(:start), FROM_UNIXTIME(:end), :op, :summit, :comment, FROM_UNIXTIME(:time))";

$stmt = $dbh->prepare($sql);

foreach ($alerts as $alert)
{
	$stmt->bindValue('start', $alert['startTime'], PDO::PARAM_INT);
	$stmt->bindValue('end', $alert['endTime'], PDO::PARAM_INT);
	$stmt->bindValue('time', $alert['time'], PDO::PARAM_INT);
	$stmt->bindValue('op', $alert['op'], PDO::PARAM_STR);
	$stmt->bindValue('summit', $alert['summit'], PDO::PARAM_STR);
	$stmt->bindValue('comment', $alert['comment'], PDO::PARAM_STR);

	$stmt->execute() or die($dbh->rollback());
}

$dbh->commit();

// vim: sw=3 ts=3 cindent

?>
