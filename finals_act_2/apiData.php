<?php
header('Content-Type: application/json');

$host = 'localhost';
$db   = 'boatdb';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    $chart     = $_GET['chart'] ?? '';
    $startDate = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? $_GET['start_date'] : null;
    $endDate   = (isset($_GET['end_date'])   && $_GET['end_date']   !== '') ? $_GET['end_date']   : null;

    // Build a reusable date filter. Wrap in DATE() so it works on datetime columns
    // (e.g. boattrip.departure) and compares cleanly by calendar day.
    function tripDateFilter($col, $start, $end, &$params) {
        $clauses = [];
        if ($start) { $clauses[] = "DATE($col) >= ?"; $params[] = $start; }
        if ($end)   { $clauses[] = "DATE($col) <= ?"; $params[] = $end; }
        return $clauses;
    }

    switch ($chart) {

        // ---- 1. Trips per Boat (Bar) — counts trips per boat, date-filtered ----
        case 'trips_per_boat': {
            $params = [];
            $clauses = tripDateFilter('bt.departure', $startDate, $endDate, $params);
            $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
            $sql = "SELECT b.boatno, COUNT(*) AS tboat
                    FROM boattrip bt
                    JOIN boat b ON b.boatid = bt.boatid
                    $where
                    GROUP BY bt.boatid, b.boatno
                    ORDER BY tboat DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $labels = [];
            $data   = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatno'];
                $data[]   = (int)$row['tboat'];
            }
            echo json_encode(['labels' => $labels, 'data' => $data]);
            break;
        }

        // ---- 2. Boat Availability (Pie) — snapshot from boat.isavailable ----
        case 'boat_availability': {
            $stmt = $pdo->query("SELECT isavailable, COUNT(*) AS count FROM boat GROUP BY isavailable");
            $rows = $stmt->fetchAll();
            $labels = ['Available', 'Not Available'];
            $data   = [0, 0];
            foreach ($rows as $row) {
                if ((int)$row['isavailable'] === 1) $data[0] = (int)$row['count'];
                else                                $data[1] = (int)$row['count'];
            }
            echo json_encode(['labels' => $labels, 'data' => $data]);
            break;
        }

        // ---- 3. Boat Distribution by Size (Doughnut) — boat.boatsize ----
        case 'boat_size': {
            $stmt = $pdo->query("SELECT boatsize, COUNT(*) AS count FROM boat GROUP BY boatsize ORDER BY FIELD(boatsize,'Small','Medium','Large')");
            $rows = $stmt->fetchAll();
            $labels = [];
            $data   = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatsize'] ?? 'Unknown';
                $data[]   = (int)$row['count'];
            }
            echo json_encode(['labels' => $labels, 'data' => $data]);
            break;
        }

        // ---- 4. Average Trip Duration (Line) — avg (arrival - departure) per day ----
        case 'trip_duration': {
            $params = [];
            $clauses = tripDateFilter('departure', $startDate, $endDate, $params);
            $clauses[] = "arrival IS NOT NULL";
            $clauses[] = "departure IS NOT NULL";
            $where = 'WHERE ' . implode(' AND ', $clauses);

            $sql = "SELECT departure, arrival FROM boattrip $where ORDER BY departure ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // group avg duration (hours) by departure date so the line shows a trend
            $byDate = [];
            foreach ($rows as $row) {
                $dep = strtotime($row['departure']);
                $arr = strtotime($row['arrival']);
                if (!$arr || !$dep) continue;
                $hours = ($arr - $dep) / 3600;
                if ($hours <= 0) continue;
                $d = date('Y-m-d', $dep);
                if (!isset($byDate[$d])) $byDate[$d] = [];
                $byDate[$d][] = $hours;
            }
            ksort($byDate);

            $labels = [];
            $data   = [];
            $allHours = [];
            foreach ($byDate as $d => $hrs) {
                $avg = array_sum($hrs) / count($hrs);
                $labels[] = $d;
                $data[]   = round($avg, 2);
                $allHours = array_merge($allHours, $hrs);
            }
            $overall = count($allHours) ? round(array_sum($allHours) / count($allHours), 2) : 0;

            echo json_encode([
                'labels'           => $labels,
                'data'             => $data,
                'overall_avg'      => $overall,
                'trip_count'       => count($allHours)
            ]);
            break;
        }

        default:
            echo json_encode(['error' => 'Unknown chart requested']);
    }

} catch (\PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
