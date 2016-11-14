# RBNHole
## RBN Spotting to SOTAWatch

### Introduction

RBNHole builds on the work done by Eric June KU6J (SK)'s RBNGate system.
 As a Gate is a neat, ordered space in a fence, and a hole is a ragged gap
 that servers a similar purpose, I've called this RBNHole in homage.

**Important**: Do **not** run an RBNHole server without the approval of the
[SOTA Management Team](http://www.sota.org.uk/).  This code is provided to 
allow others to run a server in the case of myself going SK or some other 
bus-worthy incident.

### Installation

After installation, modify /path/to/rbnhole in each script to point to the 
install path.  In the following instructions, make sure you do the same.

Make sure you have php and MySQL installed.

In order to configure MySQL to take the RBN data and alerts, it is necessary
first to use mysqlrestore to restore db_schema.sql.  You will then need to
create a number of users complete with passwords in order to allow access to
the various components.

The first user is used for pulling spots and alerts from the RBN and from 
SOTAWatch.  It has the following GRANTS in the database:
```
GRANT USAGE ON *.* TO 'rbn_spot'@'localhost' IDENTIFIED BY PASSWORD 'XXXXXXX' |
GRANT INSERT ON `rbn_hole`.* TO 'rbn_spot'@'localhost'
GRANT SELECT ON `rbn_hole`.`ExcludedActivators` TO 'rbn_spot'@'localhost'
GRANT DROP ON `rbn_hole`.`alerts` TO 'rbn_spot'@'localhost'
```
The second user is used for reading and posting spots to SOTAWatch.  This user
is defined in db_2.inc and has the following GRANTS in the database:

```
GRANT USAGE ON *.* TO 'spotter'@'localhost' IDENTIFIED BY PASSWORD 'XXXXXXX' 
GRANT SELECT, INSERT, DELETE, EXECUTE ON `rbn_hole`.* TO 'spotter'@'localhost'
```

Finally, you will need to configure cron to run the scripts at appropriate intervals.

I have the following cron tasks set up:

```
5 7,19 * * * /path/to/rbnhole/rbn_hole.php >> /path/to/rbnhole/rbn.txt
*/5 * * * * /path/to/rbnhole/fetch_alerts_db.php
*/1 * * * * /path/to/rbnhole/post_spots_db.php >> /path/to/rbnhole/spots.txt
```

You will probably want to logrotate the output from rbn_hole.php at some point.
The output from rbn.txt and spots.txt can be used to determine why an activator
wasn't spotted (usually callsign not spotted on RBN, callsign different to 
alert or callsign spotted outside of the window.

