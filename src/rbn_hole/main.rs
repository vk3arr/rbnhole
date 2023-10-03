use std::process;
use std::time::Duration;
use telnet::*;
use mysql::*;
use mysql::prelude::*;

pub mod passwords;

fn post_spot(dbh: &mut mysql::PooledConn, line: String) {
    let fields = line.split_whitespace().collect::<Vec<&str>>();
    //println!("{:?}", fields);
    
    if fields.len() < 9 {
        return;
    }

    if fields[5] == "CW" {
        let dx = fields[2];
        let freq = fields[3];
        let op = fields[4];
        let snr = fields[6];
        let wpm = fields[8];

        dbh.exec_drop("insert into rbn_spots(dx, op, freq, snr, wpm) values(
                        :dx, :op, :freq, :snr, :wpm)", 
                        (dx, op, freq, snr, wpm))
           .expect("Failed to insert spot");
    }
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

    // check we're already running
    let result = conn.query_first::<u32, &str>(
        "select no_spot_cnt from watchdog")
        .unwrap();

    match result {
        Some(cnt) => { 
            if cnt <= 2 {
                println!("Already running - quitting now");
                return;
            }
        }
        _ => {}
    }
    let _pid = conn.query_first::<u32, &str>(
        "select pid from watchdog")
        .unwrap()
        .unwrap();
    
    // update the pid in the database
    conn.exec_drop("update watchdog set pid = :pid", (process::id(),)).expect("Error updating watchdog");

    // update the spot count 
    conn.exec_drop("update watchdog set no_spot_cnt = :pid", (0,)).expect("Error updating watchdog cnt"); 

    // connect
    let mut socket = Telnet::connect(("telnet.reversebeacon.net", 7000), 80)
            .expect("Couldn't connect to RBN server");

    // read the header and ignore it
    let _header = socket.read().expect("Error reading header!");

    //println!("{:?}", header);
    
    // login
    socket.write(&String::from("VK3ARR\r\n").into_bytes())
            .expect("Could not send login");

    let mut working_buffer = String::from("");
    loop {
        let line = socket.read_timeout(Duration::new(60, 0)).expect("Error reading line");
        match line {
            telnet::Event::Data(buffer) => {
                let mut buf = working_buffer.clone();
                buf.push_str(&String::from_utf8(buffer.to_vec()).unwrap());
                
                // if we have a full line at the end of the buffer
                // don't process the last line differently.
                if buf.ends_with('\n') {
                    let lines = buf.lines();
                    
                    for l in lines {
                        println!("{:?}", l);
                        post_spot(&mut conn, l.to_string());
                    }

                    working_buffer.clear();
                } 
                else {
                    //
                    let mut lines = buf.lines().collect::<Vec<&str>>();

                    let last = lines.pop();
                    let last = last.unwrap();
                    for l in lines {
                        println!("{:?}", l);
                        post_spot(&mut conn, l.to_string());
                    }

                    working_buffer = last.to_string();
                }

            },
            _ => {}
        } 
    }
}
