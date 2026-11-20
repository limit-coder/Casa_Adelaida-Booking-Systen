<?php
declare(strict_types=1);

function get_database_connection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('CASA_DB_HOST') ?: '127.0.0.1';
    $database = getenv('CASA_DB_NAME') ?: 'casa_adelaida';
    $username = getenv('CASA_DB_USER') ?: 'root';
    $password = getenv('CASA_DB_PASSWORD') ?: '';
    $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            CustomerID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(254) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (CustomerID),
            UNIQUE KEY uq_customers_email (email)
        ) ENGINE=InnoDB"
    );

    $columns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('id', $columns, true) && !in_array('BookingID', $columns, true)) {
        $connection->exec(
            'ALTER TABLE bookings CHANGE COLUMN id BookingID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
        $columns = array_diff($columns, ['id']);
        $columns[] = 'BookingID';
    }

    if (!in_array('tour_type', $columns, true)) {
        $connection->exec(
            "ALTER TABLE bookings
             ADD COLUMN tour_type ENUM('Day Tour', 'Overnight Tour') NOT NULL DEFAULT 'Day Tour'
             AFTER pool_size"
        );
    }
    if (!in_array('check_in_time', $columns, true)) {
        $connection->exec(
            "ALTER TABLE bookings
             ADD COLUMN check_in_time TIME NOT NULL DEFAULT '08:00:00'
             AFTER check_in"
        );
    }
    if (!in_array('check_out_time', $columns, true)) {
        $connection->exec(
            "ALTER TABLE bookings
             ADD COLUMN check_out_time TIME NOT NULL DEFAULT '17:00:00'
             AFTER check_out"
        );
    }

    $bookingColumns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('customer_id', $bookingColumns, true)) {
        $connection->exec('ALTER TABLE bookings ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER BookingID');
    }

    $customerColumns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('id', $customerColumns, true) && !in_array('CustomerID', $customerColumns, true)) {
        $foreignKeys = $connection->query(
            "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'bookings'
               AND COLUMN_NAME = 'customer_id'
               AND REFERENCED_TABLE_NAME = 'customers'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($foreignKeys as $constraintName) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', (string) $constraintName)) {
                throw new RuntimeException('Unexpected customer foreign key name.');
            }
            $connection->exec('ALTER TABLE bookings DROP FOREIGN KEY `' . $constraintName . '`');
        }

        $connection->exec(
            'ALTER TABLE customers CHANGE COLUMN id CustomerID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
        $customerColumns = array_diff($customerColumns, ['id']);
        $customerColumns[] = 'CustomerID';
    }
    if (!in_array('CustomerID', $customerColumns, true)) {
        throw new RuntimeException('The customers table is missing its CustomerID column.');
    }

    $connection->exec(
        "INSERT IGNORE INTO customers (full_name, email, phone)
         SELECT full_name, email, phone FROM bookings
         WHERE email IS NOT NULL AND email <> ''"
    );
    $connection->exec(
        'UPDATE bookings AS booking
         JOIN customers AS customer ON customer.email = booking.email
         SET booking.customer_id = customer.CustomerID
         WHERE booking.customer_id IS NULL'
    );

    $customerForeignKeyExists = (bool) $connection->query(
        "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'bookings'
           AND COLUMN_NAME = 'customer_id'
           AND REFERENCED_TABLE_NAME = 'customers'
           AND REFERENCED_COLUMN_NAME = 'CustomerID'
         LIMIT 1"
    )->fetchColumn();
    if (!$customerForeignKeyExists) {
        $connection->exec(
            'ALTER TABLE bookings
             ADD CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers(CustomerID)
             ON DELETE SET NULL'
        );
    }

    $adminColumns = $connection->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('id', $adminColumns, true) && !in_array('AdminID', $adminColumns, true)) {
        $connection->exec(
            'ALTER TABLE admin_users CHANGE COLUMN id AdminID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
        $adminColumns = array_diff($adminColumns, ['id']);
        $adminColumns[] = 'AdminID';
    }

    $statusColumn = $connection->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'status'"
    )->fetchColumn();
    if (is_string($statusColumn) && !str_contains($statusColumn, 'Checked Out')) {
        $connection->exec(
            "ALTER TABLE bookings
             MODIFY status ENUM('Pending', 'Confirmed', 'Declined', 'Checked Out') NOT NULL DEFAULT 'Pending'"
        );
    }

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS booking_status_history (
            StatusHistoryID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            BookingID BIGINT UNSIGNED NOT NULL,
            StaffID BIGINT UNSIGNED NULL,
            previous_status ENUM('Pending', 'Confirmed', 'Declined', 'Checked Out') NOT NULL,
            new_status ENUM('Pending', 'Confirmed', 'Declined', 'Checked Out') NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (StatusHistoryID),
            KEY idx_booking_status_history_booking (BookingID, changed_at),
            KEY idx_booking_status_history_staff (StaffID),
            CONSTRAINT fk_booking_status_history_booking FOREIGN KEY (BookingID) REFERENCES bookings(BookingID)
                ON DELETE CASCADE,
            CONSTRAINT fk_booking_status_history_staff FOREIGN KEY (StaffID) REFERENCES admin_users(AdminID)
                ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    return $connection;
}