#!/usr/bin/php -f
<?php

// work in UTC only
date_default_timezone_set("UTC");

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

	print "Time of reconnect: " . date("H:i e") . "\n";
	echo "Attempting to connect to '$address' on port '$port'...";
	$tries = 0;
	while ($tries < 3)
	{
		$result = socket_connect($socket, $address, $port);
		if ($result === false) {
			 echo "socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)) . "\n";
			 sleep(60);
		} else {
			 echo "OK.\n";
			 break;
		}
	}
	// set up receive timeout to 1 minute
	socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>60, 'usec'=>60));

	//print "Receive timout: ";
	//print_r(socket_get_option($socket, SOL_SOCKET, SO_RCVTIMEO));

	return $socket;
}

function already_running()
{
	// simple watchdog predicated on the fact that the RBN gets
	// enough spots that if we miss two 1 minute windows with
	// no spots in the database, it's a fair bet another instance
	// of rbn_hole is no longer running

	global $dbh;
	$sql = "select no_spot_cnt from watchdog";
	$res = $dbh->query($sql);
	$row = $res->fetch_row();
	if ($row[0] >= '2')
		return false;

	return true;
}

$dbh = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if (already_running())
{
	$dbh->close();
	return;
}

print "Watchdog timeout, sending kill signal\n";
$sql = "select pid from watchdog";
$res = $dbh->query($sql);
$row = $res->fetch_row();
posix_kill($row[0], SIGKILL);

$pid = posix_getpid();
$sql = "update watchdog set pid = '$pid'";
$dbh->query($sql);
$sql = "update watchdog set no_spot_cnt='0'";
$dbh->query($sql);

$socket = connect();
print $socket;


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
	
	if ($cnt === false || $cnt == 0)// && socket_last_error($socket) != 11)
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
$dbh->close();
// vim: ts=3 sw=3 cindent noexpandtab

?>
