# RBNHole
## RBN Spotting to SOTAWatch

### Introduction

RBNHole builds on the work done by Eric June KU6J (SK)'s RBNGate system.
 As a Gate is a neat, ordered space in a fence, and a hole is a ragged gap
 that servers a similar purpose, I've called this RBNHole in homage.

**Important**: Do **not** run an RBNHole server without the approval of the
[SOTA Management Team](http://www.sota.org.uk/Contact/).  This code is 
provided to allow others to run a server in the case of myself going SK or some other bus-worthy incident.

### Installation

Make sure you have a new version of rust, cargo and MySQL/MariaDB installed.  
Be sure to edit the passwords.rs file for each component to match the SQL 
users and passwords created below.  Build with `cargo build --release`

In order to configure MySQL to take the RBN data and alerts, it is necessary
first to use mysqlrestore to restore db_schema.sql.  You will then need to
create a number of users complete with passwords in order to allow access to
the various components.

The first user is used for pulling spots and alerts from the RBN and from 
SOTAWatch.  It has the following GRANTS in the database:

```
GRANT USAGE ON *.* TO 'rbn_spot'@'localhost' IDENTIFIED BY 'XXXXXXXX';
GRANT INSERT ON `rbn_hole`.* TO 'rbn_spot'@'localhost';
GRANT SELECT ON `rbn_hole`.`ExcludedActivators` TO 'rbn_spot'@'localhost';
GRANT DROP ON `rbn_hole`.`alerts` TO 'rbn_spot'@'localhost';
GRANT SELECT,UPDATE ON `rbn_hole`.`watchdog` TO 'rbn_spot'@'localhost';
```

The second user is used for reading and posting spots to SOTAWatch.  This user
has the following GRANTS in the database:

```
GRANT USAGE ON *.* TO 'spotter'@'localhost' IDENTIFIED BY 'XXXXXXXX';
GRANT SELECT, INSERT, DELETE, EXECUTE ON `rbn_hole`.* TO 'spotter'@'localhost';
GRANT UPDATE ON `rbn_hole`.`watchdog` TO 'spotter'@'localhost';
```

The third user is for the monitoring script.  This script can be used to 
determine the connectivity of the RBN environment.

```
GRANT SELECT, INSERT ON rbn_hole.* TO 'monitoring'@'localhost' IDENTIFIED BY 'XXXXXXXX'
```

Finally, you will need to configure cron to run the scripts at appropriate 
intervals, and add the systemd service file (other init systems are available).

I have the following cron tasks set up:

```
*/5 * * * * /path/to/rbnhole/fetch_alerts >> /path/to/rbnhole/alerts.txt
*/1 * * * * /path/to/rbnhole/monitor_spots >> /path/to/rbnhole/monitor.txt
*/1 * * * * /path/to/rbnhole/post_spots_db_api >> /path/to/rbnhole/spots.txt
```

Configure systemd service with the following, after updating the path in the systemd service file:

```
root@localhost-# cp rbnhole.service /etc/systemd/system/rbnhole.service
root@localhost-# systemctl daemon-reload
root@localhost-# systemctl enable rbnhole
root@localhost-# systemctl start rbnhole
```


You will probably want to logrotate the output from rbn_hole at some point.
The output from rbn.txt and spots.txt can be used to determine why an activator
wasn't spotted (usually callsign not spotted on RBN, callsign different to 
alert or callsign spotted outside of the window.

