<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

$chart     = isset($_GET['chart']) ? $_GET['chart'] : '';
$startDate = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$endDate   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$palette = [
    'rgba(54, 162, 235, 0.6)',
    'rgba(255, 99, 132, 0.6)',
    'rgba(255, 205, 86, 0.6)',
    'rgba(75, 192, 192, 0.6)',
    'rgba(153, 102, 255, 0.6)',
    'rgba(255, 159, 64, 0.6)',
    'rgba(99, 255, 132, 0.6)',
    'rgba(201, 203, 207, 0.6)',
    'rgba(0, 188, 212, 0.6)',
    'rgba(233, 30, 99, 0.6)'
];

function dateClause($column, $start, $end, &$params) {
    $clauses = [];
    if ($start) {
        $clauses[] = "$column >= :start";
        $params[':start'] = $start . ' 00:00:00';
    }
    if ($end) {
        $clauses[] = "$column <= :end";
        $params[':end']   = $end   . ' 23:59:59';
    }
    return $clauses;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $out = [];

    switch ($chart) {

        case 'monthly_revenue': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $where[] = "boatamount IS NOT NULL";
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT DATE_FORMAT(departure, '%Y-%m') AS ym, SUM(boatamount) AS total
                    FROM boattrip $whereSql
                    GROUP BY ym ORDER BY ym";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'ym'),
                'datasets' => [[
                    'label'           => 'Monthly Revenue (PHP)',
                    'data'            => array_map('floatval', array_column($rows, 'total')),
                    'borderColor'     => 'rgb(54, 162, 235)',
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'fill'            => true,
                    'tension'         => 0.3
                ]]
            ];
            break;
        }

        case 'passenger_trends': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT DATE(departure) AS d, SUM(noofpassengers) AS total
                    FROM boattrip $whereSql
                    GROUP BY d ORDER BY d";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'd'),
                'datasets' => [[
                    'label'           => 'Passengers per Day',
                    'data'            => array_map('intval', array_column($rows, 'total')),
                    'borderColor'     => 'rgb(75, 192, 192)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.3)',
                    'fill'            => true,
                    'tension'         => 0.3
                ]]
            ];
            break;
        }

        case 'revenue_by_boat_size': {
            $params = [];
            $where  = dateClause('bt.departure', $startDate, $endDate, $params);
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT b.boatsize, SUM(bt.boatamount) AS total
                    FROM boattrip bt JOIN boat b ON b.boatid = bt.boatid
                    $whereSql
                    GROUP BY b.boatsize ORDER BY total DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'boatsize'),
                'datasets' => [[
                    'label'           => 'Revenue (PHP)',
                    'data'            => array_map('floatval', array_column($rows, 'total')),
                    'backgroundColor' => array_slice($palette, 0, count($rows))
                ]]
            ];
            break;
        }

        case 'paid_unpaid': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT
                        SUM(CASE WHEN ispaid = 1 THEN 1 ELSE 0 END) AS paid,
                        SUM(CASE WHEN ispaid = 0 OR ispaid IS NULL THEN 1 ELSE 0 END) AS unpaid
                    FROM boattrip $whereSql";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            $out = [
                'labels'   => ['Paid', 'Unpaid / Pending'],
                'datasets' => [[
                    'data'            => [(int)$row['paid'], (int)$row['unpaid']],
                    'backgroundColor' => ['rgba(75, 192, 192, 0.7)', 'rgba(255, 99, 132, 0.7)']
                ]]
            ];
            break;
        }

        case 'top_drivers': {
            $params = [];
            $where  = dateClause('bt.departure', $startDate, $endDate, $params);
            $where[] = "bt.driverid IS NOT NULL";
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT CONCAT(d.firstname,' ',d.lastname) AS name, COUNT(*) AS trips
                    FROM boattrip bt JOIN driver d ON d.driverid = bt.driverid
                    $whereSql
                    GROUP BY bt.driverid
                    ORDER BY trips DESC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'name'),
                'datasets' => [[
                    'label'           => 'Trips',
                    'data'            => array_map('intval', array_column($rows, 'trips')),
                    'backgroundColor' => 'rgba(54, 162, 235, 0.6)'
                ]]
            ];
            break;
        }

        case 'cancelled_by_driver': {
            $params = [];
            $where  = dateClause('bt.datecancelled', $startDate, $endDate, $params);
            $where[] = "bt.datecancelled IS NOT NULL";
            $where[] = "bt.driverid IS NOT NULL";
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT CONCAT(d.firstname,' ',d.lastname) AS name, COUNT(*) AS cnt
                    FROM boattrip bt JOIN driver d ON d.driverid = bt.driverid
                    $whereSql
                    GROUP BY bt.driverid
                    ORDER BY cnt DESC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'name'),
                'datasets' => [[
                    'label'           => 'Cancelled Trips',
                    'data'            => array_map('intval', array_column($rows, 'cnt')),
                    'backgroundColor' => 'rgba(255, 99, 132, 0.6)'
                ]]
            ];
            break;
        }

        case 'safety_compliance': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT
                      AVG(checklifevest)*100         AS lifevest,
                      AVG(checklifevestchildren)*100 AS lifevestkids,
                      AVG(checkfireextandsand)*100   AS fire,
                      AVG(checktrashbin)*100         AS trashbin,
                      AVG(checkgas)*100              AS gas,
                      AVG(checkhelper)*100           AS helper,
                      AVG(checkboatmanuniformid)*100 AS uniform
                    FROM boattrip $whereSql";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $r = $stmt->fetch() ?: [];
            $labels = ['Life Vest','Children Vest','Fire Ext.','Trash Bin','Gas','Helper','Uniform ID'];
            $vals = [
                round((float)($r['lifevest']     ?? 0), 1),
                round((float)($r['lifevestkids'] ?? 0), 1),
                round((float)($r['fire']         ?? 0), 1),
                round((float)($r['trashbin']     ?? 0), 1),
                round((float)($r['gas']          ?? 0), 1),
                round((float)($r['helper']       ?? 0), 1),
                round((float)($r['uniform']      ?? 0), 1),
            ];
            $out = [
                'labels'   => $labels,
                'datasets' => [[
                    'label'           => 'Compliance %',
                    'data'            => $vals,
                    'backgroundColor' => 'rgba(75, 192, 192, 0.3)',
                    'borderColor'     => 'rgb(75, 192, 192)',
                    'pointBackgroundColor' => 'rgb(75, 192, 192)',
                    'fill'            => true
                ]]
            ];
            break;
        }

        case 'inspections_by_personnel': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $where[] = "inspectedby <> 0";
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT inspectedby, COUNT(*) AS cnt
                    FROM boattrip $whereSql
                    GROUP BY inspectedby
                    ORDER BY cnt DESC LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_map(function($r){ return 'User #'.$r['inspectedby']; }, $rows),
                'datasets' => [[
                    'label'           => 'Trips Inspected',
                    'data'            => array_map('intval', array_column($rows, 'cnt')),
                    'backgroundColor' => 'rgba(153, 102, 255, 0.6)'
                ]]
            ];
            break;
        }

        case 'avg_speed_per_boat': {
            $sql = "SELECT boatno, AVG(iot_speedKnots) AS avgspd
                    FROM boat
                    WHERE iot_speedKnots IS NOT NULL
                    GROUP BY boatid
                    ORDER BY avgspd DESC LIMIT 15";
            $rows = $pdo->query($sql)->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'boatno'),
                'datasets' => [[
                    'label'           => 'Avg Speed (Knots)',
                    'data'            => array_map('floatval', array_column($rows, 'avgspd')),
                    'backgroundColor' => 'rgba(255, 159, 64, 0.6)'
                ]]
            ];
            break;
        }

        case 'revenue_by_tour_type': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $where[] = "islandtourtype IS NOT NULL AND islandtourtype <> ''";
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT islandtourtype, SUM(boatamount) AS total
                    FROM boattrip $whereSql
                    GROUP BY islandtourtype ORDER BY total DESC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'islandtourtype'),
                'datasets' => [[
                    'label'           => 'Revenue (PHP)',
                    'data'            => array_map('floatval', array_column($rows, 'total')),
                    'backgroundColor' => array_slice($palette, 0, count($rows))
                ]]
            ];
            break;
        }

        case 'capacity_vs_passengers': {
            $params = [];
            $where  = dateClause('bt.departure', $startDate, $endDate, $params);
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT b.boatno, b.boatcapacity, AVG(bt.noofpassengers) AS avgpax
                    FROM boat b JOIN boattrip bt ON bt.boatid = b.boatid
                    $whereSql
                    GROUP BY b.boatid ORDER BY b.boatcapacity DESC LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'boatno'),
                'datasets' => [
                    [
                        'type'            => 'bar',
                        'label'           => 'Capacity',
                        'data'            => array_map('intval', array_column($rows, 'boatcapacity')),
                        'backgroundColor' => 'rgba(54, 162, 235, 0.6)'
                    ],
                    [
                        'type'            => 'line',
                        'label'           => 'Avg Passengers',
                        'data'            => array_map(function($v){return round((float)$v,1);}, array_column($rows, 'avgpax')),
                        'borderColor'     => 'rgb(255, 99, 132)',
                        'backgroundColor' => 'transparent',
                        'tension'         => 0.3
                    ]
                ]
            ];
            break;
        }

        case 'payment_completion': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT
                        SUM(CASE WHEN ispaid = 1 THEN 1 ELSE 0 END) AS paid,
                        SUM(CASE WHEN ispaid = 0 OR ispaid IS NULL THEN 1 ELSE 0 END) AS pending
                    FROM boattrip $whereSql";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            $out = [
                'labels'   => ['Completed', 'Outstanding'],
                'datasets' => [[
                    'data'            => [(int)$row['paid'], (int)$row['pending']],
                    'backgroundColor' => ['rgba(75, 192, 192, 0.7)', 'rgba(255, 205, 86, 0.7)']
                ]]
            ];
            break;
        }

        case 'avg_speed_by_size': {
            $sql = "SELECT boatsize, AVG(iot_speedKnots) AS avgspd
                    FROM boat
                    WHERE iot_speedKnots IS NOT NULL
                    GROUP BY boatsize ORDER BY avgspd DESC";
            $rows = $pdo->query($sql)->fetchAll();
            $out = [
                'labels'   => array_column($rows, 'boatsize'),
                'datasets' => [[
                    'label'           => 'Avg Speed (Knots)',
                    'data'            => array_map(function($v){return round((float)$v,2);}, array_column($rows, 'avgspd')),
                    'backgroundColor' => array_slice($palette, 0, count($rows))
                ]]
            ];
            break;
        }

        case 'peak_hours': {
            $params = [];
            $where  = dateClause('departure', $startDate, $endDate, $params);
            $where[] = "departure IS NOT NULL";
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $sql = "SELECT HOUR(departure) AS h, COUNT(*) AS cnt
                    FROM boattrip $whereSql
                    GROUP BY h ORDER BY h";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $map = array_fill(0, 24, 0);
            foreach ($rows as $r) { $map[(int)$r['h']] = (int)$r['cnt']; }
            $labels = [];
            for ($i = 0; $i < 24; $i++) { $labels[] = sprintf('%02d:00', $i); }
            $out = [
                'labels'   => $labels,
                'datasets' => [[
                    'label'           => 'Departures',
                    'data'            => $map,
                    'borderColor'     => 'rgb(153, 102, 255)',
                    'backgroundColor' => 'rgba(153, 102, 255, 0.2)',
                    'fill'            => true,
                    'tension'         => 0.3
                ]]
            ];
            break;
        }

        default:
            http_response_code(400);
            $out = ['error' => 'Unknown chart: ' . $chart];
    }

    echo json_encode($out);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
