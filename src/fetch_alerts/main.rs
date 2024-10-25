use mysql::*;
use mysql::prelude::*;
use serde::*;
use chrono::prelude::*;
use regex::Regex;
use chrono::Duration;

pub mod passwords;

#[derive(Deserialize, Debug)]
struct AlertModel {
    epoch: String,

    #[serde(rename="dateActivated")]
    date_activated: String,

    #[serde(rename="activatingCallsign")]
    activating_callsign: String,

    #[serde(rename="associationCode")]
    association_code: String,

    #[serde(rename="summitCode")]
    summit_code: String,

    #[serde(rename="summitDetails")]
    summit_details: String,

    comments: Option<String>,

    #[serde(rename="posterCallsign")]
    poster_callsign: String
}

#[derive(Deserialize, Debug)]
struct Alert {
    op: String,
    time: i64,
    #[serde(rename="startTime")]
    start_time: i64,
    #[serde(rename="endTime")]
    end_time: i64,
    summit: String,
    comment: String
}

fn fetch_alerts(dbh: &mut mysql::PooledConn) -> Vec<Alert> {
    let epoch = reqwest::blocking::get(
                    "https://api-db2.sota.org.uk/api/alerts/epoch")
                    .expect("Could not reach server")
                    .text()
                    .expect("Failed to fetch epoch");

    let old_epoch = std::fs::read_to_string("epoch.txt").unwrap_or_default();
    if epoch.trim() == old_epoch.trim() {
        // return an empty alert list
        println!("Avoided new alert fetch");
        return Vec::<Alert>::new();
    }

    let json = reqwest::blocking::get(
                    "https://api-db2.sota.org.uk/api/alerts/24/all/all")
                    .expect("Could not reach server")
                    .text()
                    .expect("Failed to fetch JSON");
    //println!("JSON: {:?}", json);
    let alerts_api: Vec<AlertModel> = serde_json::from_str(&json)
                .expect("Unable to parse JSON");

    //println!("{:?}", alertsAPI);
    let mut alerts = Vec::<Alert>::new();
    let mut saved_epoch = false;
    let re = Regex::new(r"S\+([0-9]+)").unwrap();
    let re2 = Regex::new(r"^S-([0-9]+)").unwrap();
    let re3 = Regex::new(r" S-([0-9]+)").unwrap();
    let excre = Regex::new("RBNN|NoRBNGate|NoRBNHole").unwrap();

    for alert in alerts_api {
        if !saved_epoch {
            //
            std::fs::write("epoch.txt", alert.epoch).expect("Could not write file");
            saved_epoch = true;
        }
        // are we an excluded operator?
        let exc : i64 =
            dbh.exec_first(
                    "select count(op) from ExcludedActivators where op=:op",
                    (&alert.activating_callsign,))
               .expect("Failed to query db")
               .unwrap();

        if exc > 0 {
            continue;
        }

        let t = NaiveDateTime::parse_from_str(&alert.date_activated,
                                              "%Y-%m-%dT%H:%M:%SZ")
                            .unwrap().and_utc();

        let mut s_time = t.checked_sub_signed(Duration::hours(1)).unwrap();
        let mut e_time = t.checked_add_signed(Duration::hours(3)).unwrap();
        let comm = match alert.comments {
            Some(s) => {
                s.clone()
            },
            None => {
                String::from("")
            }
        };

        if let Some(c) = re.captures(&comm) {
            let shift = c.get(1).unwrap().as_str().parse::<i64>().unwrap();
            e_time = t.checked_add_signed(Duration::hours(shift)).unwrap();
        }

        if let Some(c) = re3.captures(&comm) {
            let shift = c.get(1).unwrap().as_str().parse::<i64>().unwrap();
            s_time = t.checked_sub_signed(Duration::hours(shift)).unwrap();
        }

        if let Some(c) = re2.captures(&comm) {
            let shift = c.get(1).unwrap().as_str().parse::<i64>().unwrap();
            s_time = t.checked_sub_signed(Duration::hours(shift)).unwrap();
        }

        assert!(excre.is_match("Test RBNN comment"));
        assert!(excre.is_match("TestNoRBNGate  comment"));
        assert!(excre.is_match("TeNoRBNHole st comment"));

        if excre.is_match(&comm) {
            continue;
        }

        let a = Alert {
            op: alert.activating_callsign,
            start_time: s_time.timestamp(),
            end_time: e_time.timestamp(),
            comment: comm,
            summit: alert.association_code + "/" + &alert.summit_code,
            time: t.timestamp()
        };

        alerts.push(a);
    }

    //println!("{:?}", alerts);
    alerts
}

fn main() {
    // Connect to database
    let mut url = String::from("mysql://");
    url.push_str(passwords::DB_USER);
    url.push(':');
    url.push_str(passwords::DB_PASS);
    url.push('@');
    url.push_str(passwords::DB_HOST);
    url.push_str(":3306/");
    url.push_str(passwords::DB_NAME);
    let pool = Pool::new(Opts::from_url(&url).unwrap()).unwrap();
    let mut conn = pool.get_conn().unwrap();


    let alerts = fetch_alerts(&mut conn);
    if !alerts.is_empty() {
        let mut t = conn.start_transaction(TxOpts::default())
                        .expect("Could not create transaction");

        // empty our alerts table
        t.query_drop("truncate table alerts").expect("Failed to truncate");

        let stmt = t.exec_batch("insert into alerts(startTime, endTime, op,
                                summit, comment, time) values(
                                FROM_UNIXTIME(:start), FROM_UNIXTIME(:end),
                                :op, :summit, :comment,
                                FROM_UNIXTIME(:time))",
                                alerts.iter().map(|a| params!{
                                    "start" => a.start_time,
                                    "end" => a.end_time,
                                    "time" => a.time,
                                    "op" => &a.op,
                                    "summit" => &a.summit,
                                    "comment" => &a.comment,
                                }));

        match stmt {
            Ok(_s) => { t.commit().unwrap(); },
            Err(s) => { println!("{}", s); t.rollback().unwrap(); }
        }
    }
}
