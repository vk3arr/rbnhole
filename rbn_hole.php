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

	// set up receive timeout to 1 minute
	socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>60, 'usec'=>0));

	print "Receive timout: ";
	print_r(socket_get_option($socket, SOL_SOCKET, SO_RCVTIMEO));

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
while ((time() - $sT) <= 45000)
{
   $cnt = socket_recv($socket, $buf, 10, 0);
	
	if ($cnt === false)// && socket_last_error($socket) != 11)
	{
		print time() . "\n";;
		print (socket_last_error($socket)) . "\n";
		print socket_strerror(socket_last_error($socket)) . "\n";
		socket_close($socket);
		$socket = connect();
		read_header($socket);
		continue;
	}
	else
	{
		socket_clear_error();
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

socket_close($socket);

// vim: ts=3 sw=3 cindent noexpandtab

?>
