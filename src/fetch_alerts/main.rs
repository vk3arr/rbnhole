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

    let old_epoch = std::fs::read_to_string("epoch.txt").unwrap_or(String::new());
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

        let re = Regex::new(r"S\+([0-9]+)").unwrap();
        if let Some(c) = re.captures(&comm) {
            let shift = i64::from_str_radix(
                    c.get(1).unwrap().as_str(), 10).unwrap();
            e_time = t.checked_add_signed(Duration::hours(shift)).unwrap();
        }

        let re = Regex::new(r" S-([0-9]+)").unwrap();
        if let Some(c) = re.captures(&comm) {
            let shift = i64::from_str_radix(
                    c.get(1).unwrap().as_str(), 10).unwrap();
            s_time = t.checked_sub_signed(Duration::hours(shift)).unwrap();
        }

        let re = Regex::new(r"^S-([0-9]+)").unwrap();
        if let Some(c) = re.captures(&comm) {
            let shift = i64::from_str_radix(
                    c.get(1).unwrap().as_str(), 10).unwrap();
            s_time = t.checked_sub_signed(Duration::hours(shift)).unwrap();
        }

        let exc = Regex::new("RBNN|NoRBNGate|NoRBNHole").unwrap();
        assert_eq!(exc.is_match("Test RBNN comment"), true);
        assert_eq!(exc.is_match("TestNoRBNGate  comment"), true);
        assert_eq!(exc.is_match("TeNoRBNHole st comment"), true);

        if exc.is_match(&comm) {
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
    return alerts;
}

fn main() {
    // Connect to database
    let mut url = String::from("mysql://");
    url.push_str(passwords::DB_USER);
    url.push_str(":");
    url.push_str(passwords::DB_PASS);
    url.push_str("@");
    url.push_str(passwords::DB_HOST);
    url.push_str(":3306/");
    url.push_str(passwords::DB_NAME);
    let pool = Pool::new(Opts::from_url(&url).unwrap()).unwrap();
    let mut conn = pool.get_conn().unwrap();


    let alerts = fetch_alerts(&mut conn);
    if alerts.len() != 0 {
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
