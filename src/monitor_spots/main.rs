use mysql::*;
use mysql::prelude::*;
mod passwords;


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

    conn.query_drop("insert into monitoring(
                     select NOW(), count(*) from rbn_spots)")
        .expect("SQL failed");
}
