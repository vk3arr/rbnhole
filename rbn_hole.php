#!/usr/bin/php -f
<?php



$lastFetch = -1;

// work in UTC only
date_default_timezone_set("UTC");

function fetch_alerts()
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
			$summit = str_replace("<a href =\"summits.php?summit=", "", $alertLine);
			$summit = substr($summit, 0, strpos($summit, "\""));
			
			$alert['summit'] = $summit;
			array_push($tmp, $alert);
			$state = "NEW";
		}
	}

	$lastFetch = time();

	return $tmp;
}

function check_alerts($op, $tm)
{
	global $alerts;
	print "check_alerts()\n";
	$t = mktime(intval(substr($tm, 0, 2)), intval(substr($tm, 2, 2)));
  	$minDiff = PHP_INT_MAX;
	$alert = array();

	foreach ($alerts as $alt);
	{
		
		// find closest alert that matches op
		if (trim($alt['op']) == trim($op))
		{
			if (abs($t - $alt['time']) <= $minDiff)
			{
				$alert = $alt;
			}
		}	
	}	

	// if within 2 hours
	if ($minDiff < 7200)
		return $alert['summit'];

	return FALSE;
}

function post_spot($line)
{
	global $dbh;
	
	print "$line\n";
	if (strpos($line, " CW ") === FALSE)
	{
		return;
	}

	$fields = array_values(array_filter(explode(" ", trim($line)), 'strlen'));	

	//print_r($fields);
	$dx = $fields[0];

	if ($dx == "Welcome")
	{
		//print "Ignoring first line\n";
		return;
	}

	$dx = $fields[2];
	$freq = $fields[3];
	$op = $fields[4];
	$snr = $fields[6];
	$wpm  = $fields[8];
	

	$sql = "insert into rbn_spots (dx, op, freq, snr, wpm) values('$dx', '$op', '$freq', '$snr', '$wpm')";
	$dbh->query($sql);
}

//$alerts = fetch_alerts();

include_once('/path/to/rbnhole/db.inc');

function connect()
{
	/* Create a TCP/IP socket. */
	$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	if ($socket === false) {
   	 echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
	} else {
   	 echo "OK.\n";
	}

	$address = gethostbyname('relay2.reversebeacon.net');
	$port = 7000;

	echo "Attempting to connect to '$address' on port '$port'...";
	$result = socket_connect($socket, $address, $port);
	if ($result === false) {
   	 echo "socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)) . "\n";
	} else {
	    echo "OK.\n";
	}

	return $socket;
}

$socket = connect();
print $socket;

$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

function read_header(&$socket)
{
	// flush the buffer
	$cnt = 0;
	$buf = "";
	$login = "VK3ARR\r\nset/skimmer\r\n";
	$login = "VK3ARR\r\n";

	while(true)
	{
		$cnt += socket_recv($socket, $buf, 80, MSG_DONTWAIT);
		print "$buf";
		if ($cnt == 182)
		{
			socket_write($socket, "VK3ARR\r\n", strlen($login));
			break;
		}
	}
}

read_header($socket);

$line = "";
$cnt = 0;
$buf = "";
$sT = time();
// run for a little over a day
while ((time() - $sT) <= 100000)
{
	$cnt = socket_recv($socket, $buf, 1, MSG_PEEK);
	if ($cnt === false)
	{
		print time() . "\n";;
		print (socket_last_error($socket)) . "\n";
		print socket_strerror(socket_last_error($socket)) . "\n";
		socket_close($socket);
		$socket = connect();
		read_header($socket);
		$line = "";
		continue;
	}

   $cnt = socket_recv($socket, $buf, 80, MSG_DONTWAIT);
	
	if ($cnt === false && socket_last_error($socket) != 11)
	{
		print time() . "\n";;
		print (socket_last_error($socket)) . "\n";
		print socket_strerror(socket_last_error($socket)) . "\n";
		socket_close($socket);
		$socket = connect();
		read_header($socket);
		continue;
	}		

	if ($cnt != 0)
	{
		$line .= $buf;

		if (($pos = strpos($line, "\r\n")) !== FALSE)
		{
			$spotLine = substr($line, 0, $pos);
			
			post_spot($spotLine);			
			
			$line = ltrim(substr($line, $pos));
		}
	}
}
/*
for ($i=0;$i<12;$i++)
   $line = socket_read($socket, 80, PHP_NORMAL_READ);

socket_write($socket, $login, strlen($login));
   $line = socket_read($socket, 80, PHP_NORMAL_READ);
   $line = socket_read($socket, 80, PHP_NORMAL_READ);


//$socket = fopen("php://stdin", "r");			
//stream_set_read_buffer($socket, 0);
while (($line = fgets($socket, 80)) !== FALSE)
{
	//print " 1 " . microtime() . "\n";
	// update the alerts every 15 minutes
	if ((time() - $lastFetch) > 900)
		$alerts = fetch_alerts();

	
	//print " 2 " . microtime().  "\n";

	//$fields = array_values(array_filter(explode(" ", trim($line))));
	//print_r($fields);
	//print " 3 " . microtime() . "\n";
	$fields = explode(" ", trim($line));	

	$dx = $fields[0];
	//print "$dx\n";
	// only look at skimmer spots
	//if (strpos($dx, "-#") !== FALSE)
	//{
      $dx = substr($fields[0], 0, strlen($fields[0])-3);
		$op = $fields[2];
		$freq = $fields[1];
		$mode = "CW";

		//print "   $op - $mode\n";

		if ($mode == "CW")
		{
			$snr = $fields[3];
			$wpm = $fields[4];
			$tm = $fields[5];

			// get summit reference from alerts
			$ref = check_alerts($op, $tm);

			if ($ref !== FALSE)
				print "$op spotted by $dx on $freq (snr $snr @ $wpm)\n";
		}
		print "$dx $freq $op $snr $wpm $tm\n";
	///}
}
*/
socket_close($socket);

// vim: ts=3 sw=3 cindent noexpandtab

?>
