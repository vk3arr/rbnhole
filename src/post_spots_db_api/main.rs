use mysql::*;
use mysql::prelude::*;
use std::collections::HashMap;
use serde::*;
use std::str::FromStr;
use chrono::prelude::*;
use regex::Regex;
//use chrono::Duration;

pub mod passwords;

#[derive(Deserialize, Debug)]
struct SpotModel {
    id: u64,

    #[serde(rename="userID")]
    user_id: u64,
    
    #[serde(rename="timeStamp")]
    timestamp: String,
    
    comments: Option<String>,

    #[serde(rename = "associationCode")]
    association_code: String,
    
    #[serde(rename = "summitCode")]
    summit_code: String,

    #[serde(rename = "activatorCallsign")]
    activator_callsign: String,

    #[serde(rename = "activatorName")]
    activator_name: String,

    #[serde(rename = "summitDetails")]
    summit_details: String,
    mode: String,
    frequency: String
}

#[derive(Deserialize, Debug)]
struct Spot {
    op: String,
    freq: f64
}

#[derive(Debug)]
struct RBNSpot {
    op: String,
    dx: String,
    freq: f64,
    snr: i64,
    wpm: i64,
    time: i64,
    summit: String,
}

#[derive(Debug, Deserialize)]
struct JWT {
    access_token: String,
    refresh_token: String,
}

fn fetch_sw_spots(dbh: &mut mysql::PooledConn) {
    let json = reqwest::blocking::get(
                    "https://api2.sota.org.uk/api/spots/-1/")
                    //"http://localhost:63004/api/spots/-1/")
                    .expect("Could not reach server")
                    .text()
                    .expect("Failed to fetch JSON");
    //println!("JSON: {:?}", json);
    let spots_api: Vec<SpotModel> = serde_json::from_str(&json)
                .expect("Unable to parse JSON");

    //println!("{:?}", spots_api);
    for spot in spots_api {
        if spot.mode.to_ascii_uppercase() == "CW" {
            // check if spot ID has already been seen
            let scnt : i64 = dbh.exec_first(
                    "select count(*) from sw_spots where id=:id",
                    (spot.id,))
                .expect("Failed to query db")
                .unwrap();
            
            // nope, it's a virgin spot
            if scnt == 0 {
                let band: i32 = f32::from_str(&spot.frequency)
                                    .unwrap()
                                    .floor() as i32;

                let mut summit = String::from(spot.association_code + "/");
                summit.push_str(&spot.summit_code);

                dbh.exec_drop("insert into PostedSpots 
                               (op, band, freq, summit, time) values
                               (:op, :band, :freq, :summit, :tstamp)",
                               params!{ "op" => spot.activator_callsign.clone(),
                                       "band" => band,
                                       "freq" => spot.frequency.clone(),
                                       "summit" => summit,
                                       "tstamp" => spot.timestamp.clone() })
                             .expect("Could not insert spot");
                
                dbh.exec_drop("insert into sw_spots 
                               (id, op, freq, time) values
                               (:id, :op, :freq, :tstamp)",
                               params!{ "op" => spot.activator_callsign.clone(),
                                       "id" => spot.id,
                                       "freq" => spot.frequency.clone(),
                                       "tstamp" => spot.timestamp.clone() })
                             .expect("Could not insert sw_spot");

            }
        }
    }
}

fn update_watchdog(dbh: &mut mysql::PooledConn) {
    let scnt : i64 = dbh.query_first(
        "select count(*) from rbn_spots")
    .expect("Failed to query db")
    .unwrap();
    
    if scnt == 0 {
        dbh.query_drop("update watchdog set no_spot_cnt = no_spot_cnt+1")
           .expect("Failed to update spot cnt");
    }
}

fn authenticate() -> JWT {
    // authenticate against the SSO
    let params = [("client_id", "sotawatch"),
                  ("grant_type", "password"),
                  ("username", passwords::RBNHOLE_USER),
                  ("password", passwords::RBNHOLE_PASS)];

    let client = reqwest::blocking::Client::new();
    let res = client.post("https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/token")
        .form(&params)
        .send()
        .expect("Could not reach SSO")
        .text()
        .expect("Could not fetch body");
    
    let jwt: JWT = serde_json::from_str(&res).expect("Could not parse JWT");
    
    jwt
}

fn logout(jwt: &JWT) { 
    let params = [("client_id", "sotawatch"),
                  ("refresh_token", &jwt.refresh_token)];

    let client = reqwest::blocking::Client::new();
    client.post("https://sso.sota.org.uk/auth/realms/SOTA/protocol/openid-connect/logout")
        .form(&params)
        .bearer_auth(&jwt.access_token)
        .send()
        .expect("Could not reach SSO")
        .text()
        .expect("Could not fetch body");
}

fn post_spot(dbh: &mut mysql::PooledConn, spot: &RBNSpot, access_token: &str) {
    
    let summit_code: Vec<&str> = spot.summit.split('/').collect();
    let assoc: &str = summit_code[0];
    let sotaref: &str = summit_code[1];
    
    let freq: f64 = spot.freq / 1000.0;
    let upper_freq = freq + 0.00125;
    let lower_freq = freq - 0.00125;
    let band: i32 = freq.floor() as i32;
    let op = spot.op.clone();
    let dx : Vec<&str> = spot.dx.split('-').collect();
    let comment = format!("[RBNHole] at {} {} WPM {} dB SNR", 
                            dx[0], spot.wpm, spot.snr);

    let opcnt: i64 = dbh.exec_first("select count(op) from PostedSpots 
                                where freq < :upper and freq > :lower 
                                and op = :op 
                                and time > SUBTIME(NOW(), SEC_TO_TIME(3600))",
                                params!{ "upper" => upper_freq, 
                                         "lower" => lower_freq, "op" => &op })
                .expect("Failed to query db")
                .unwrap();
    
    if opcnt > 0 {
        // already spotted
        println!("No spot for {:?} due to spot lockout", &op);
        return;
    }

    let mut data = HashMap::new();
    data.insert("activatorCallsign", op.to_string());
    data.insert("associationCode", assoc.to_string());
    data.insert("summitCode", sotaref.to_string());
    data.insert("callsign", "RBNHOLE".to_string());
    data.insert("mode", "CW".to_string());
    data.insert("comments", comment);
    data.insert("frequency", format!("{:.4}", freq)); 

    let mut url = "https://api2.sota.org.uk/api/spots/dontchecksummit?client=sotawatch&user=rbnhole";
    //let mut url = "http://localhost:63004/api/spots/dontchecksummit?client=sotawatch&user=rbnhole";

    if Regex::new(r"^[A-Z]{2}-[0-9]{3}").unwrap().is_match(&sotaref) {
        url = "https://api2.sota.org.uk/api/spots?client=sotawatch&user=rbnhole";
        //url = "http://localhost:63004/api/spots?client=sotawatch&user=rbnhole";
    }

    let ch = reqwest::blocking::Client::new();
    let resp = ch.post(url)
                  .json(&data)
                  .header("id_token", access_token)
                  .bearer_auth(access_token)
                  .send().expect("Could not post spot")
                  .text().unwrap();
    println!("{:?}", resp);
    dbh.exec_drop("delete from PostedSpots where op = :op", 
                    params!{ "op" => &op })
        .expect("Could not delete from PostedSpots");

    dbh.exec_drop("insert into PostedSpots (op, band, freq, summit, time)
                    values(:op, :band, :freq, :summit, NOW())", 
                   params!{ "op" => &op, band, freq, 
                            "summit" => &spot.summit })
        .expect("Unable to insert postspot");
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
    let pool = Pool::new(url).unwrap();
    let mut conn = pool.get_conn().unwrap();
    let mut jwt = JWT {
                access_token: String::from(""),
                refresh_token: String::from("") };

    update_watchdog(&mut conn);
    fetch_sw_spots(&mut conn);

    // remove all rbn records that have no corresponding alert
    conn.query_drop("delete from rbn_spots where op not in 
                     (select b.op from alerts b)")
                   .expect("Unable to delete non-op rbn_spots");

    // delete any spots older than 10 minutes
    conn.query_drop("delete from rbn_spots where 
                      TIMESTAMPDIFF(MINUTE, time, NOW()) > 10")
                   .expect("Unable to delete old rbn_spots");

    let res = conn.query_map::<_, _, _, Spot>(
                          "select op, freq from SeenActivators a
                          where cnt = (select max(cnt) from SeenActivators 
                                       where op = a.op)", 
                          |(op, freq)| { 
                                Spot { op, freq } 
                          })
                  .unwrap();

    for row in res {
        if jwt.access_token == "" {
            jwt = authenticate();
        }

        let spot = conn.exec_map::<_, &str,_,_, RBNSpot>(
                    "select dx, a.op, freq, snr, wpm, 
                            a.time, summit 
                     from rbn_spots a, alerts b 
                     where a.op = :op and a.freq=:freq 
                            and a.op = b.op and startTime <= a.time 
                            and a.time <= endTime 
                     order by ABS(TIMESTAMPDIFF(MINUTE,b.time,a.time)) asc, 
                            snr desc 
                     limit 1", params!{ "op" => row.op, "freq" => row.freq},
                     |(dx, op, freq, snr, wpm, time, summit)| {
                         RBNSpot { dx: from_value(dx), op: from_value(op), 
                                   freq: from_value(freq), 
                                   snr: from_value(snr), wpm: from_value(wpm), 
                                   time: from_value::<NaiveDateTime>(time).timestamp(), 
                                   summit: from_value(summit) }
                     }).unwrap();

        if spot.len() == 1 {
            let s = &spot[0];
            post_spot(&mut conn, &s, &jwt.access_token);
            conn.exec_drop("delete from rbn_spots where op = :op", 
                           params!{ "op" => &s.op })
                .expect("Failed to delete op spots");
        }
    }

    if jwt.access_token != "" {
        logout(&jwt);
    }
}
