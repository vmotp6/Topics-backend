<?php
// quick import helper - run from CLI: php import_sdata2.php /path/to/sdata2.csv
// It will recreate the sdata2 table and load all rows.

require_once __DIR__ . '/session_config.php';
require_once '../../Topics-frontend/frontend/config.php';

// support both CLI and HTTP invocation
$csv = '';
if (php_sapi_name() === 'cli') {
    $csv = $argv[1] ?? '';
} else {
    // web request - GET parameter
    $csv = $_GET['file'] ?? '';
    header('Content-Type: text/plain; charset=utf-8');
}
if (!$csv || !file_exists($csv)) {
    $msg = "Usage: php import_sdata2.php /full/path/to/sdata2.csv\n";
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $msg);
        exit(1);
    } else {
        echo $msg;
        exit;
    }
}

$conn = getDatabaseConnection();
if (!$conn) {
    fwrite(STDERR, "Cannot connect to database\n");
    exit(1);
}

// drop existing table (optional)
$conn->query("DROP TABLE IF EXISTS sdata2");

// create simple schema that contains the columns we care about
$sql = "CREATE TABLE IF NOT EXISTS sdata2 (
    school_code VARCHAR(50) DEFAULT NULL,
    school_name VARCHAR(255) DEFAULT NULL,
    dept_code VARCHAR(50) DEFAULT NULL,
    dept_name VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    `type` VARCHAR(100) DEFAULT NULL,
    INDEX(school_name),
    INDEX(dept_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);
if ($conn->error) {
    fwrite(STDERR, "Error creating sdata2 table: " . $conn->error . "\n");
    exit(1);
}

if (($handle = fopen($csv, 'r')) === false) {
    fwrite(STDERR, "Unable to open CSV file $csv\n");
    exit(1);
}
// skip header
$header = fgetcsv($handle);

$insert = $conn->prepare("INSERT INTO sdata2 (school_code, school_name, dept_code, dept_name, city, `type`) VALUES (?, ?, ?, ?, ?, ?)");
if (!$insert) {
    fwrite(STDERR, "Prepare failed: " . $conn->error . "\n");
    exit(1);
}

$count = 0;
while (($row = fgetcsv($handle)) !== false) {
    // expect 6 columns
    $school_code = $row[0] ?? '';
    $school_name = $row[1] ?? '';
    $dept_code   = $row[2] ?? '';
    $dept_name   = $row[3] ?? '';
    $city        = $row[4] ?? '';
    $type        = $row[5] ?? '';
    $insert->bind_param('ssssss', $school_code, $school_name, $dept_code, $dept_name, $city, $type);
    $insert->execute();
    if ($insert->error) {
        fwrite(STDERR, "Insert error: " . $insert->error . "\n");
    }
    $count++;
}
fclose($handle);

fwrite(STDOUT, "Imported $count rows into sdata2\n");
$conn->close();
