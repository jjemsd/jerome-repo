<?php
// apiData.php — single API endpoint that returns chart data as JSON.
// Call it as: apiData.php?chart=<key>   (e.g. apiData.php?chart=trips_per_boat)
header('Content-Type: application/json');

// ====================== DATABASE CONFIG ======================
// Change $db to whatever your database is named (you imported it as 'boatdb').
$host    = 'localhost';
$db      = 'boatdb';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $chart = $_GET['chart'] ?? '';

    switch ($chart) {

        // 1. Trips per Boat (Bar) — count trips per boat ----------------------
        case 'trips_per_boat': {
            $sql = "SELECT b.boatno, COUNT(*) AS cnt
                    FROM boattrip bt
                    JOIN boat b ON b.boatid = bt.boatid
                    WHERE bt.boatid <> 0
                    GROUP BY bt.boatid, b.boatno
                    ORDER BY cnt DESC
                    LIMIT 10";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'boatno'),
                'data'   => array_map('intval', array_column($rows, 'cnt'))
            ]);
            break;
        }

        // 2. Total Monthly Revenue (Line) — sum boatamount per month ----------
        case 'monthly_revenue': {
            $sql = "SELECT DATE_FORMAT(departure, '%Y-%m') AS ym, SUM(boatamount) AS total
                    FROM boattrip
                    WHERE departure IS NOT NULL AND boatamount IS NOT NULL
                    GROUP BY ym
                    ORDER BY ym";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'ym'),
                'data'   => array_map('floatval', array_column($rows, 'total'))
            ]);
            break;
        }

        // 3. Revenue by Boat Size (Bar) — sum boatamount grouped by size ------
        case 'revenue_by_size': {
            $sql = "SELECT b.boatsize, SUM(bt.boatamount) AS total
                    FROM boattrip bt
                    JOIN boat b ON b.boatid = bt.boatid
                    WHERE bt.boatamount IS NOT NULL
                    GROUP BY b.boatsize
                    ORDER BY FIELD(b.boatsize,'Small','Medium','Large')";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'boatsize'),
                'data'   => array_map('floatval', array_column($rows, 'total'))
            ]);
            break;
        }

        // 4. Paid vs Unpaid Trips (Doughnut) — ispaid ratio -------------------
        case 'paid_unpaid': {
            $sql = "SELECT
                        SUM(CASE WHEN ispaid = 1 THEN 1 ELSE 0 END) AS paid,
                        SUM(CASE WHEN ispaid = 0 OR ispaid IS NULL THEN 1 ELSE 0 END) AS unpaid
                    FROM boattrip";
            $r = $pdo->query($sql)->fetch();
            echo json_encode([
                'labels' => ['Paid', 'Unpaid / Pending'],
                'data'   => [(int)$r['paid'], (int)$r['unpaid']]
            ]);
            break;
        }

        // 5. Boat Availability (Pie) — isavailable snapshot -------------------
        case 'boat_availability': {
            $rows = $pdo->query("SELECT isavailable, COUNT(*) AS cnt FROM boat GROUP BY isavailable")->fetchAll();
            $data = [0, 0]; // [Available, Not Available]
            foreach ($rows as $row) {
                if ((int)$row['isavailable'] === 1) $data[0] = (int)$row['cnt'];
                else                                $data[1] = (int)$row['cnt'];
            }
            echo json_encode(['labels' => ['Available', 'Not Available'], 'data' => $data]);
            break;
        }

        // 6. Boat Distribution by Size (Doughnut) — count per size ------------
        case 'boat_size_distribution': {
            $sql = "SELECT boatsize, COUNT(*) AS cnt
                    FROM boat
                    GROUP BY boatsize
                    ORDER BY FIELD(boatsize,'Small','Medium','Large')";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'boatsize'),
                'data'   => array_map('intval', array_column($rows, 'cnt'))
            ]);
            break;
        }

        // 7. Top Drivers by Trip Count (Horizontal Bar) — driver join ---------
        case 'top_drivers': {
            // exclude the DEFAULT placeholder driver (driverid 1 = "DEFAULT FN MN")
            // and seed rows (status '0') so real drivers are visible
            $sql = "SELECT CONCAT(d.firstname,' ',d.lastname) AS name, COUNT(*) AS cnt
                    FROM boattrip bt
                    JOIN driver d ON d.driverid = bt.driverid
                    WHERE bt.driverid IS NOT NULL
                      AND bt.driverid <> 1
                      AND bt.status <> '0'
                    GROUP BY bt.driverid, name
                    ORDER BY cnt DESC
                    LIMIT 10";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'name'),
                'data'   => array_map('intval', array_column($rows, 'cnt'))
            ]);
            break;
        }

        // 8. Trips by Status (Bar) — group by status -------------------------
        case 'trips_by_status': {
            // exclude placeholder seed rows (status '0') so real trip statuses show
            $sql = "SELECT COALESCE(NULLIF(status,''),'Unknown') AS status, COUNT(*) AS cnt
                    FROM boattrip
                    WHERE status <> '0' AND status IS NOT NULL
                    GROUP BY status
                    ORDER BY cnt DESC";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'status'),
                'data'   => array_map('intval', array_column($rows, 'cnt'))
            ]);
            break;
        }

        // 9. Average Speed by Boat Size (Bar) — IoT telemetry ----------------
        case 'avg_speed_by_size': {
            $sql = "SELECT boatsize, ROUND(AVG(iot_speedKnots),2) AS avgspd
                    FROM boat
                    WHERE iot_speedKnots IS NOT NULL
                    GROUP BY boatsize
                    ORDER BY FIELD(boatsize,'Small','Medium','Large')";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode([
                'labels' => array_column($rows, 'boatsize'),
                'data'   => array_map('floatval', array_column($rows, 'avgspd'))
            ]);
            break;
        }

        // 10. Safety Compliance Rate (Radar) — % of "yes" across checks ------
        case 'safety_compliance': {
            // compute compliance over REAL trips only (status '0' rows are all-zero seed data)
            $sql = "SELECT
                        ROUND(AVG(checklifevest)*100,1)         AS lifevest,
                        ROUND(AVG(checklifevestchildren)*100,1) AS lifevest_child,
                        ROUND(AVG(checkfireextandsand)*100,1)   AS fireext,
                        ROUND(AVG(checktrashbin)*100,1)         AS trashbin,
                        ROUND(AVG(checkgas)*100,1)              AS gas,
                        ROUND(AVG(checkhelper)*100,1)           AS helper
                    FROM boattrip
                    WHERE status <> '0' AND status IS NOT NULL";
            $r = $pdo->query($sql)->fetch();
            echo json_encode([
                'labels' => ['Life Vest', 'Child Vest', 'Fire Ext', 'Trash Bin', 'Gas', 'Helper'],
                'data'   => [
                    (float)$r['lifevest'], (float)$r['lifevest_child'], (float)$r['fireext'],
                    (float)$r['trashbin'], (float)$r['gas'], (float)$r['helper']
                ]
            ]);
            break;
        }

        default:
            echo json_encode(['error' => 'Unknown chart requested: ' . $chart]);
    }

} catch (\PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
