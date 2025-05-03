<?php
// =============================================================================
// IOT Dweetr v2505031630
// – A lightweight message board for machine-to-machine communication
// Website: https://dweetr.io
// - by DeepThought.ws
// =============================================================================

// Force all PHP date/time functions to use UTC regardless of server configuration.
// This ensures consistency across distributed systems.
date_default_timezone_set('UTC');

// =============================================================================
// Database configuration – adjust these placeholders to match your MariaDB setup
// =============================================================================
$host    = 'db_host';
$db      = 'db_name';
$user    = 'db_username';
$pass    = 'db_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dweets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thing VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_private TINYINT(1) DEFAULT 0,
            token VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

/**
 * Clean up old dweets for a given "thing".
 *
 * This routine performs two maintenance tasks:
 *  1. Deletes any dweet that is older than 24 hours.
 *  2. Prunes the history so that only the latest five dweets remain (if more exist).
 *
 * @param PDO    $pdo   Our active PDO database connection.
 * @param string $thing The label or identifier for which we are cleaning up dweets.
 */
function cleanupDweets($pdo, $thing) {
    // -------------------------------------------------------------------------
    // Step 1: Purge all dweets older than 24 hours to keep the dataset fresh.
    // -------------------------------------------------------------------------
    $stmt = $pdo->prepare("DELETE FROM dweets WHERE thing = ? AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $stmt->execute([$thing]);

    // -------------------------------------------------------------------------
    // Step 2: Limit the number of stored dweets to the latest 5.
    // Grab all IDs for the specified thing in descending order (most recent first).
    // -------------------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT id FROM dweets WHERE thing = ? ORDER BY id DESC");
    $stmt->execute([$thing]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // If there are more than 5 records, slice off the oldest ones for deletion.
    if (count($ids) > 5) {
        // Select IDs beyond the 5 most recent.
        $toDelete = array_slice($ids, 5);
        // Create a comma-separated placeholder string for binding (e.g.: "?, ?, ...").
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $pdo->prepare("DELETE FROM dweets WHERE id IN ($placeholders)");
        $stmt->execute($toDelete);
    }
}

/**
 * Process an incoming dweet (a new message for a given thing).
 *
 * All GET query parameters (except reserved "private" and "auth") are captured
 * and stored as a JSON payload. Optionally, if marking a dweet as "private" is
 * requested via ?private=1, a unique token is generated.
 *
 * @param PDO    $pdo   Active PDO connection.
 * @param string $thing The identifier to which this dweet belongs.
 */
function sendDweet($pdo, $thing) {
    // -------------------------------------------------------------------------
    // Capture all GET parameters as data payload.
    // Reserved keys 'private' and 'auth' are intercepted to toggle private mode 
    // or to prevent potential security issues.
    // -------------------------------------------------------------------------
    $data = $_GET;
    $is_private = false;
    if (isset($data['private']) && $data['private'] == '1') {
        $is_private = true;
        unset($data['private']);
    }
    if (isset($data['auth'])) {
        unset($data['auth']);
    }
    // Validate that there is at least one key/value pair to store.
    if (empty($data)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'No data provided']);
        exit;
    }
    
    // -------------------------------------------------------------------------
    // For private dweets, a unique token is generated. This token allows
    // subsequent private retrieval of the dweet.
    // -------------------------------------------------------------------------
    $token = null;
    if ($is_private) {
        $token = md5(uniqid(rand(), true));
    }
    
    // Convert the collected parameters to JSON for streamlined storage.
    $content = json_encode($data);

    // Insert dweet data into the database. Note: NOW() ensures UTC timestamp.
    $stmt = $pdo->prepare("INSERT INTO dweets (thing, content, is_private, token, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$thing, $content, $is_private ? 1 : 0, $token]);

    // Execute cleanup logic to maintain storage limits and freshness for this "thing".
    cleanupDweets($pdo, $thing);

    // Respond with success status and include token if applicable.
    header('Content-Type: application/json');
    $response = [
        'status'  => 'success',
        'thing'   => $thing,
        'data'    => $data,
        'private' => $is_private,
    ];
    if ($is_private) {
        $response['token'] = $token;
    }
    echo json_encode($response);
    exit;
}

/**
 * Retrieve and return the latest dweet for a specified "thing".
 *
 * If an "auth" parameter is provided (as a token), private dweets that match the
 * token are allowed to be retrieved along with public ones.
 *
 * @param PDO    $pdo   Active PDO connection.
 * @param string $thing The identifier for which the latest dweet is being requested.
 */
function getLatestDweet($pdo, $thing) {
    // -------------------------------------------------------------------------
    // Use the provided auth token, if any, to allow private message retrieval.
    // Otherwise, restrict to public dweets only.
    // -------------------------------------------------------------------------
    $auth = isset($_GET['auth']) ? $_GET['auth'] : null;
    if ($auth) {
        // Return the most recent message that is either public,
        // or private and matches the provided token.
        $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND (is_private = 0 OR (is_private = 1 AND token = ?)) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$thing, $auth]);
    } else {
        // Only public dweets are eligible.
        $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND is_private = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$thing]);
    }
    $dweet = $stmt->fetch();

    // Set the JSON output header and return the dweet (if found).
    header('Content-Type: application/json');
    if ($dweet) {
        // Decode the stored JSON content into a PHP array.
        $dweet['content'] = json_decode($dweet['content'], true);
        echo json_encode(['this' => $dweet]);
    } else {
        echo json_encode(['this' => null, 'message' => 'No dweets found']);
    }
    exit;
}

/**
 * Retrieve all (recent) dweets for the specified "thing".
 *
 * Including private messages is possible by supplying the correct auth token.
 *
 * @param PDO    $pdo   Active PDO connection.
 * @param string $thing The identifier for which dweets are being queried.
 */
function getAllDweets($pdo, $thing) {
    $auth = isset($_GET['auth']) ? $_GET['auth'] : null;
    if ($auth) {
        // Combine public dweets with private ones that have a matching token.
        $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND (is_private = 0 OR (is_private = 1 AND token = ?)) ORDER BY id DESC");
        $stmt->execute([$thing, $auth]);
    } else {
        // Only public messages should be returned.
        $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND is_private = 0 ORDER BY id DESC");
        $stmt->execute([$thing]);
    }
    $dweets = $stmt->fetchAll();
    
    // Convert each dweet's JSON content to a PHP array for easier consumption.
    foreach ($dweets as &$dweet) {
        $dweet['content'] = json_decode($dweet['content'], true);
    }
    header('Content-Type: application/json');
    echo json_encode(['this' => $dweets]);
    exit;
}

/**
 * Long-polling endpoint to "listen" for new dweets in real time.
 *
 * Optionally, a "since" parameter (the last seen auto-increment ID) can be provided
 * to receive only messages with a higher ID than that value.
 *
 * @param PDO    $pdo   Active PDO connection.
 * @param string $thing The identifier we are monitoring for new messages.
 */
function listenDweets($pdo, $thing) {
    // Increase PHP's script time limit to allow for long polling (up to 40 seconds).
    set_time_limit(40);
    $auth = isset($_GET['auth']) ? $_GET['auth'] : null;
    
    // "since" represents the last known message ID. Default to zero if not provided.
    $last_id = isset($_GET['since']) ? (int)$_GET['since'] : 0;

    $start = time();
    $found = false;
    $dweet = null;
    
    // Loop for up to 30 seconds waiting for a new dweet.
    while (time() - $start < 30) {
        if ($auth) {
            // Look for the next dweet with an ID greater than $last_id, allowing private matches.
            $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND id > ? AND (is_private = 0 OR (is_private = 1 AND token = ?)) ORDER BY id ASC LIMIT 1");
            $stmt->execute([$thing, $last_id, $auth]);
        } else {
            // Retrieve only public dweets beyond the last seen ID.
            $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND id > ? AND is_private = 0 ORDER BY id ASC LIMIT 1");
            $stmt->execute([$thing, $last_id]);
        }
        $dweet = $stmt->fetch();
        
        if ($dweet) {
            $found = true;
            break;
        }
        // Sleep half a second before checking again to reduce load on the database.
        usleep(500000);
    }
    
    header('Content-Type: application/json');
    if ($found) {
        // Decode JSON content before returning.
        $dweet['content'] = json_decode($dweet['content'], true);
        echo json_encode(['this' => $dweet]);
    } else {
        // No new dweets were found during the polling window.
        echo json_encode(['this' => null, 'message' => 'No new dweets']);
    }
    exit;
}

/**
 * Computes and retrieves the total number of dweets stored in the database.
 *
 * This information is used for displaying system stats on the home page.
 *
 * @param PDO $pdo Active PDO connection.
 * @return int The total count of dweets.
 */
function getTotalDweets($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM dweets");
    $result = $stmt->fetch();
    return $result['total'];
}

/**
 * Render an HTML home page.
 *
 * This page displays instructions and examples (mimicking dweet.cc) for users
 * to interact with the API. It also shows a form for quick testing.
 *
 * @param PDO $pdo Active PDO connection.
 */
function showHomePage($pdo) {
    $total = getTotalDweets($pdo);
    header('Content-Type: text/html');
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to dweetr</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: auto; }
        h1 { color: #333; }
        pre { background: #f4f4f4; padding: 10px; }
        input[type=text] { width: 100%; padding: 10px; margin: 5px 0; }
        input[type=submit] { padding: 10px 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Welcome to IOT dweetr</h1>
    <p>Simple machine-to-machine messaging over HTTP. No setup. No auth. Just post and get lightweight JSON.</p>
    <h2>Quick Start</h2>
    <h3>Send a message:</h3>
    <pre>curl "http://' . $_SERVER['HTTP_HOST'] . '/dweet/for/my-thing-name?temperature=21&unit=c"</pre>
    <h3>Get the latest message:</h3>
    <pre>curl "http://' . $_SERVER['HTTP_HOST'] . '/get/latest/dweet/for/my-thing-name"</pre>
    <h3>Get all dweets for a thing:</h3>
    <pre>curl "http://' . $_SERVER['HTTP_HOST'] . '/get/dweets/for/my-thing-name"</pre>
    <h3>Listen for real-time updates:</h3>
    <pre>curl -N "http://' . $_SERVER['HTTP_HOST'] . '/listen/for/dweets/from/my-thing-name"</pre>
    <h3>Realtime updates in browser:</h3>
    <pre>http://' . $_SERVER['HTTP_HOST'] . '/realtime.html?thing=my-thing-name</pre>
    <h3>Follow a dweet with graphing:</h3>
    <pre>curl "http://' . $_SERVER['HTTP_HOST'] . '/follow/my-thing-name"</pre>    
    <h3>Send a private dweet, JSON will return unique token:</h3>
    <pre>curl "http://' . $_SERVER['HTTP_HOST'] . '/dweet/for/my-thing-name?temp=23&private=1"</pre>
    <h3>Fetch the latest private dweet:</h3>
    <pre>curl "http://' . $_SERVER['HTTP_HOST'] . '/get/latest/dweet/for/my-thing-name?auth=YOUR_TOKEN"</pre>
    <hr>
    <h3>Try It Now</h3>
    <form method="get" action="/dweet/for/">
        <label for="thing">Thing Name:</label><br>
        <input type="text" id="thing" name="thing" placeholder="my-thing-name" required><br>
        <label for="params">Query Parameters (key=value&key2=value2):</label><br>
        <input type="text" id="params" name="params" placeholder="temperature=21&unit=c" required><br><br>
        <input type="submit" value="SEND">
    </form>
    <p>Total Dweets: ' . $total . '</p>
</div>
</body>
</html>';
    exit;
}

// Add this function to render the follow page with complete history graphs
function showFollowPage($pdo, $thing) {
    // Fetch the complete history for this thing in ascending order (oldest first)
    $stmt = $pdo->prepare("SELECT * FROM dweets WHERE thing = ? AND is_private = 0 ORDER BY id ASC");
    $stmt->execute([$thing]);
    $dweets = $stmt->fetchAll();

    header('Content-Type: text/html');
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($thing) . ' - dweetr.io</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f7f7f7; }
        .header { background: #2196f3; color: #fff; padding: 30px 20px 10px 20px; }
        .header h1 { margin: 0; }
        .container { max-width: 900px; margin: 30px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 30px; }
        .tab-btn { background: #f7f7f7; border: none; padding: 10px 30px; font-size: 1.1em; cursor: pointer; }
        .tab-btn.active { background: #fff; border-bottom: 2px solid #2196f3; font-weight: bold; }
        .chart-div { margin-bottom: 40px; }
    </style>
</head>
<body>
<div class="container">
    <h1>IOT dweetr - ' . htmlspecialchars($thing) . '</h1>
    <p>Complete history for this thing</p>
    <div>
        <button class="tab-btn active" id="visualBtn" onclick="showVisual()">Visual</button>
        <button class="tab-btn" id="rawBtn" onclick="showRaw()">Raw</button>
    </div>
    <div id="visualView">
        <!-- A unified container to output each field in the order it first appears -->
        <div id="fieldsContainer"></div>
    </div>
    <div id="rawView" style="display:none;">
        <pre id="rawJson"></pre>
    </div>
</div>
<script>
// Pass the complete history from PHP
let dweets = ' . json_encode($dweets) . ';

// Create a unified container where all fields (graphs or dropdowns) will be appended
let fieldsContainer = document.getElementById("fieldsContainer");

// Build an array of keys in the order they first appear across all dweets
let orderedKeys = [];
dweets.forEach(dw => {
    let content = JSON.parse(dw.content);
    Object.keys(content).forEach(key => {
        if (orderedKeys.indexOf(key) === -1) {
            orderedKeys.push(key);
        }
    });
});

// For each key in the union (ordered by its first appearance)
orderedKeys.forEach(key => {
    // Determine the type of this field by using the first occurrence where it is defined.
    let firstValue = null;
    for (let i = 0; i < dweets.length; i++) {
        let content = JSON.parse(dweets[i].content);
        if (content[key] !== undefined) {
            firstValue = content[key];
            break;
        }
    }
    
    if (firstValue !== null && !isNaN(firstValue)) {
        // Numeric field: Create a chart.
        let chartDiv = document.createElement("div");
        chartDiv.className = "chart-div";
        
        let title = document.createElement("h3");
        title.textContent = key;
        chartDiv.appendChild(title);
        
        let canvas = document.createElement("canvas");
        canvas.id = "chart-" + key;
        canvas.height = 80;
        chartDiv.appendChild(canvas);
        fieldsContainer.appendChild(chartDiv);
        
        // Extract labels and values for this numeric field from all dweets.
        let labels = [];
        let values = [];
        dweets.forEach(dw => {
            let content = JSON.parse(dw.content);
            if (content[key] !== undefined && !isNaN(content[key])) {
                labels.push(dw.created_at);
                values.push(Number(content[key]));
            }
        });
        
        if (labels.length > 0) {
            let ctx = canvas.getContext("2d");
            new Chart(ctx, {
                type: "line",
                data: {
                    labels: labels,
                    datasets: [{
                        label: key,
                        data: values,
                        borderColor: "#2196f3",
                        backgroundColor: "rgba(33,150,243,0.1)",
                        fill: true,
                        tension: 0.2
                    }]
                },
                options: {
                    scales: {
                        x: { display: true, title: { display: true, text: "Time" } },
                        y: { display: true, title: { display: true, text: key } }
                    }
                }
            });
        }
    } else {
        // Non-numeric field: Create a dropdown menu.
        let div = document.createElement("div");
        div.style.marginBottom = "20px";
        
        let label = document.createElement("label");
        label.textContent = key;
        label.style.display = "block";
        label.style.fontWeight = "bold";
        div.appendChild(label);
        
        let select = document.createElement("select");
        select.style.width = "100%";
        select.style.padding = "5px";
        
        // For each dweet display an option with its timestamp and the corresponding value.
        dweets.forEach(dw => {
            let content = JSON.parse(dw.content);
            if (content[key] !== undefined && isNaN(content[key])) {
                let option = document.createElement("option");
                option.text = dw.created_at + " - " + content[key];
                select.appendChild(option);
            }
        });
        
        div.appendChild(select);
        fieldsContainer.appendChild(div);
    }
});

// Toggle views between Visual and Raw
function showVisual() {
    document.getElementById("visualView").style.display = "";
    document.getElementById("rawView").style.display = "none";
    document.getElementById("visualBtn").classList.add("active");
    document.getElementById("rawBtn").classList.remove("active");
}
function showRaw() {
    document.getElementById("visualView").style.display = "none";
    document.getElementById("rawView").style.display = "";
    document.getElementById("visualBtn").classList.remove("active");
    document.getElementById("rawBtn").classList.add("active");
    document.getElementById("rawJson").textContent = JSON.stringify(dweets, null, 2);
}
</script>
</body>
</html>';
    exit;
}


// =============================================================================
// ROUTING: Add this route before the fallback
// =============================================================================

$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($route, '/'));

if ($parts[0] === 'follow' && isset($parts[1])) {
    $thing = $parts[1];
    showFollowPage($pdo, $thing);
}

// ----------------------------------------------------------------------------
// Default route: When no specific endpoint is requested, show the home page
// ----------------------------------------------------------------------------
if (empty($parts[0])) {
    showHomePage($pdo);

// ----------------------------------------------------------------------------
// Endpoint: /dweet/for/{thing} – Send a new dweet for a specified thing.
// ----------------------------------------------------------------------------
} elseif ($parts[0] === 'dweet' && isset($parts[1]) && $parts[1] === 'for') {
    // First, try to set the thing name from the GET parameter.
    if (isset($_GET['thing']) && !empty($_GET['thing'])) {
        $thing = $_GET['thing'];
    } else {
        // If not provided in GET, then check the URL path.
        $thing = isset($parts[2]) ? $parts[2] : null;
    }

    if ($thing === null) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Thing name is required']);
        exit;
    }

    // If a "params" parameter exists, parse its key/value pairs into the GET array.
    if (isset($_GET['params'])) {
        parse_str($_GET['params'], $parsedParams);
        // Merge these parsed parameters with any existing GET parameters.
        $_GET = array_merge($_GET, $parsedParams);
        // Remove duplicate/temporary keys.
        unset($_GET['thing'], $_GET['params']);
    }

    sendDweet($pdo, $thing);

// ----------------------------------------------------------------------------
// Endpoint: /get/latest/dweet/for/{thing} – Retrieve the latest dweet.
// ----------------------------------------------------------------------------
} elseif ($parts[0] === 'get' && isset($parts[1]) && $parts[1] === 'latest' && isset($parts[2]) && $parts[2] === 'dweet' && isset($parts[3]) && $parts[3] === 'for') {
    $thing = isset($parts[4]) ? $parts[4] : null;
    if ($thing === null) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Thing name is required']);
        exit;
    }
    getLatestDweet($pdo, $thing);

// ----------------------------------------------------------------------------
// Endpoint: /get/dweets/for/{thing} – Retrieve all recent dweets for a thing.
// ----------------------------------------------------------------------------
} elseif ($parts[0] === 'get' && isset($parts[1]) && $parts[1] === 'dweets' && isset($parts[2]) && $parts[2] === 'for') {
    $thing = isset($parts[3]) ? $parts[3] : null;
    if ($thing === null) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Thing name is required']);
        exit;
    }
    getAllDweets($pdo, $thing);

// ----------------------------------------------------------------------------
// Endpoint: /listen/for/dweets/from/{thing} – Listen via long polling for new dweets.
// ----------------------------------------------------------------------------
} elseif ($parts[0] === 'listen' && isset($parts[1]) && $parts[1] === 'for' && isset($parts[2]) && $parts[2] === 'dweets' && isset($parts[3]) && $parts[3] === 'from') {
    $thing = isset($parts[4]) ? $parts[4] : null;
    if ($thing === null) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Thing name is required']);
        exit;
    }
    listenDweets($pdo, $thing);

// ----------------------------------------------------------------------------
// Endpoint: /realtime.html – A simple web page to view real-time updates using Ajax.
// ----------------------------------------------------------------------------
} elseif ($parts[0] === 'realtime.html') {
    header('Content-Type: text/html');
    // Sanitize the "thing" input to prevent XSS.
    $thing = isset($_GET['thing']) ? htmlspecialchars($_GET['thing']) : '';
    
    // Obtain the current highest dweet ID so that the client only receives new dweets.
    $latestId = 0;
    if (!empty($thing)) {
        $stmt = $pdo->prepare("SELECT id FROM dweets WHERE thing = ? AND is_private = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$thing]);
        $result = $stmt->fetch();
        if ($result) {
            $latestId = (int)$result['id'];
        }
    }
    
    // Render a minimal HTML page with embedded JavaScript for long polling.
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Realtime Dweets</title>
</head>
<body>
<h1>Realtime Dweets for "' . $thing . '"</h1>
<pre id="output">Waiting for updates...</pre>
<script>
// The last known dweet ID at page load – only messages with a higher ID will be displayed.
let lastId = ' . $latestId . ';

// The listen() function implements a long-polling mechanism using XMLHttpRequest.
function listen() {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "/listen/for/dweets/from/' . $thing . '?since=" + encodeURIComponent(lastId), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            var res = JSON.parse(xhr.responseText);
            if (res.this) {
                // Display the new dweet in a pretty-printed JSON format.
                document.getElementById("output").textContent = JSON.stringify(res.this, null, 2);
                // Update lastId so that subsequent polls only fetch newer dweets.
                if (res.this.id) {
                    lastId = res.this.id;
                }
            }
            // Immediately start a new long poll upon completion.
            listen();
        }
    };
    xhr.send();
}
listen();
</script>
</body>
</html>';
    exit;

// ----------------------------------------------------------------------------
// Fallback: If no endpoint matches, render the home page.
// ----------------------------------------------------------------------------
} else {
    showHomePage($pdo);
}
?>
