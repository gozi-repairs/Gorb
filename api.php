<?php
// api.php - Zelda Real-Time MMO PHP Backend with Combat & HP System
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dataFile = __DIR__ . '/state.json';

// Atomic State File Lock
$fp = @fopen($dataFile, 'c+');
if (!$fp || !@flock($fp, LOCK_EX)) {
    echo json_encode(['status' => 'error', 'message' => 'State file locked']);
    exit;
}

$filesize = @filesize($dataFile);
$state = [];
if ($filesize > 0) {
    $raw = @fread($fp, $filesize);
    $state = json_decode($raw, true);
    if (!is_array($state)) $state = [];
}

$now = microtime(true);

// 1. Remove players inactive for > 6.0 seconds
foreach ($state as $id => $data) {
    if (!isset($data['t']) || ($now - $data['t']) > 6.0) {
        unset($state[$id]);
    }
}

// 2. Process incoming player updates & combat actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && isset($input['id'])) {
        $id = (string)$input['id'];

        // Initialize player if new
        if (!isset($state[$id])) {
            $state[$id] = [
                'id'     => $id,
                'n'      => substr(trim($input['n'] ?? 'Hero'), 0, 12),
                's'      => (int)($input['s'] ?? 0),
                'x'      => (int)($input['x'] ?? 8),
                'y'      => (int)($input['y'] ?? 8),
                'd'      => (int)($input['d'] ?? 0),
                'hp'     => 100,
                'isDead' => 0,
                't'      => $now
            ];
        }

        // Handle Respawn Signal
        if (!empty($input['respawn'])) {
            $state[$id]['hp'] = 100;
            $state[$id]['isDead'] = 0;
            $state[$id]['x'] = 8;
            $state[$id]['y'] = 8;
        } else {
            // Update movement/position if alive
            if (empty($state[$id]['isDead'])) {
                $state[$id]['n'] = substr(trim($input['n'] ?? $state[$id]['n']), 0, 12);
                $state[$id]['s'] = (int)($input['s'] ?? $state[$id]['s']);
                $state[$id]['x'] = (int)($input['x'] ?? $state[$id]['x']);
                $state[$id]['y'] = (int)($input['y'] ?? $state[$id]['y']);
                $state[$id]['d'] = (int)($input['d'] ?? $state[$id]['d']);
            }
        }

        // Process combat hits dealt by this player (25 HP damage per hit)
        if (isset($input['hits']) && is_array($input['hits']) && empty($state[$id]['isDead'])) {
            foreach ($input['hits'] as $targetId) {
                $targetId = (string)$targetId;
                if (isset($state[$targetId]) && empty($state[$targetId]['isDead'])) {
                    $state[$targetId]['hp'] -= 25;
                    if ($state[$targetId]['hp'] <= 0) {
                        $state[$targetId]['hp'] = 0;
                        $state[$targetId]['isDead'] = 1;
                    }
                }
            }
        }

        $state[$id]['t'] = $now;
    }
}

// Write updated atomic state back
@ftruncate($fp, 0);
@rewind($fp);
@fwrite($fp, json_encode($state));
@flock($fp, LOCK_UN);
@fclose($fp);

// 3. Output active players array
$players = [];
foreach ($state as $id => $d) {
    $players[$id] = [
        'id'     => $d['id'],
        'n'      => $d['n'],
        's'      => $d['s'],
        'x'      => $d['x'],
        'y'      => $d['y'],
        'd'      => $d['d'],
        'hp'     => $d['hp'] ?? 100,
        'isDead' => $d['isDead'] ?? 0
    ];
}

echo json_encode([
    'status'   => 'ok',
    'httpCode' => 200,
    'players'  => $players,
    'time'     => round($now * 1000)
]);
?>