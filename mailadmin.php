<?php
$file = '/etc/spamassassin/local.cf';
$backupFile = $file . '.bak';
$whitelistEntries = [];
$blacklistEntries = [];

// Define the expected access token
$expectedToken = 'e99a18c428cb38d5f260853678922e03';

// Check if the access token is present and correct
if (!isset($_GET['access_token']) || $_GET['access_token'] !== $expectedToken) {
    die("Access denied: This page is restricted.");
}


// Helper function to check service status
function getServiceStatus($service) {
    $output = [];
    exec("systemctl is-active $service", $output, $status);
    return $status === 0 ? 'Active' : 'Inactive';
}

// Helper function to restart a service
if (isset($_POST['restart_service'])) {
    $service = escapeshellarg($_POST['restart_service']);
    exec("sudo systemctl restart $service", $output, $returnVar);
    header("Location: mailadmin.php?access_token=" . urlencode($expectedToken));
    exit;
}

// List of services to check
$services = [
    'apache2' => 'Apache2',
    'postfix' => 'Postfix',
    'dovecot' => 'Dovecot',
    'spamassassin' => 'SpamAssassin',
    'fail2ban' => 'Fail2Ban',
];

// Get the status of each service
$serviceStatuses = [];
foreach ($services as $serviceKey => $serviceName) {
    $serviceStatuses[$serviceKey] = getServiceStatus($serviceKey);
}


// Read current whitelist and blacklist entries
$content = file_get_contents($file);
preg_match_all('/^whitelist_from\s+([^\s]+)$/m', $content, $whitelistMatches);
$whitelistEntries = $whitelistMatches[1];
preg_match_all('/^blacklist_from\s+([^\s]+)$/m', $content, $blacklistMatches);
$blacklistEntries = $blacklistMatches[1];

// Handle deletion request
if (isset($_GET['delete']) && isset($_GET['type']) && in_array($_GET['type'], ['whitelist', 'blacklist'])) {
    $emailToDelete = filter_var($_GET['delete'], FILTER_VALIDATE_EMAIL);
    $listType = ($_GET['type'] === 'blacklist') ? 'blacklist_from' : 'whitelist_from';

    if ($emailToDelete) {
        // Backup the config file before modifying
        copy($file, $backupFile);

        // Remove the specific whitelist or blacklist entry
        $content = preg_replace('/^' . preg_quote($listType, '/') . '\s+' . preg_quote($emailToDelete, '/') . '\s*$/m', '', $content);
        file_put_contents($file, $content);

        // Restart SpamAssassin
        exec('sudo systemctl restart spamassassin', $output, $returnVar);

        // Redirect to the main page with the access token
        header("Location: mailadmin.php?access_token=" . urlencode($expectedToken));
        exit;
    }
}

// Handle new whitelist/blacklist addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    $listType = ($_POST['list_type'] === 'blacklist') ? 'blacklist_from' : 'whitelist_from';

    // Check for duplicates before adding
    if ($email) {
        $isDuplicate = in_array($email, $whitelistEntries) || in_array($blacklistEntries);

        if (!$isDuplicate) {
            $entry = "$listType $email" . PHP_EOL;

            // Backup the config file before modifying
            copy($file, $backupFile);

            // Determine the insertion point based on the list type
            if ($listType === 'whitelist_from') {
                $lastPos = strrpos($content, 'whitelist_from');
                $insertionPoint = ($lastPos !== false) ? strpos($content, "\n", $lastPos) + 1 : 0;
                $content = substr_replace($content, $entry, $insertionPoint, 0);
            } elseif ($listType === 'blacklist_from') {
                $lastPos = strrpos($content, 'blacklist_from');
                if ($lastPos !== false) {
                    $insertionPoint = strpos($content, "\n", $lastPos) + 1;
                    $content = substr_replace($content, $entry, $insertionPoint, 0);
                } else {
                    $whitelistEndPos = strrpos($content, 'whitelist_from');
                    $insertionPoint = ($whitelistEndPos !== false) ? strpos($content, "\n", $whitelistEndPos) + 1 : 0;
                    $content = substr_replace($content, $entry, $insertionPoint, 0);
                }
            }

            // Save changes and restart SpamAssassin
            file_put_contents($file, $content);
            exec('sudo systemctl restart spamassassin', $output, $returnVar);

            // Redirect to refresh the list and avoid form resubmission with the access token
            header("Location: mailadmin.php?access_token=" . urlencode($expectedToken));
            exit;
        } else {
            echo "<p style='color: red;'>Entry already exists in the list.</p>";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(gethostname()) . " manager"; ?></title>

    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: auto;
            padding: 20px;
            background-color: #f9f9f9;
        }
        h1 {
            color: #333;
            text-align: center;
        }
        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: space-between;
        }
        .box {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            flex: 1 1 30%;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
        }
        input[type="email"], input[type="text"], select {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        button:hover {
            background-color: #45a049;
        }
        .delete-btn {
            color: red;
            font-weight: bold;
            font-size: 1.2em;
            cursor: pointer;
            text-decoration: none;
            margin-left: 10px;
        }
        .delete-btn:hover {
            color: darkred;
        }
        .description {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 15px;
        }
        ul {
            list-style: none;
            padding: 0;
        }
        ul li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }

        .dashboard {
            background: #f0f0f0;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .service-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        .status-active { color: green; }
        .status-inactive { color: red; }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <h1><?php echo htmlspecialchars(gethostname()) . " manager"; ?></h1>

    
    <div class="container">

        <!-- Dashboard for service status and restart -->
        <div class="dashboard">
            <h2>Service Dashboard</h2>
            <?php foreach ($services as $serviceKey => $serviceName): ?>
                <div class="service-status">
                    <span><?php echo $serviceName; ?>:</span>
                    <span class="<?php echo $serviceStatuses[$serviceKey] === 'Active' ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo $serviceStatuses[$serviceKey]; ?>
                    </span>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="restart_service" value="<?php echo htmlspecialchars($serviceKey); ?>">
                        <button type="submit">Restart</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>


        <!-- Form for adding entries -->
        <div class="box">
            <h2>Add Email to List</h2>
            <p class="description">
                Use this form to add email addresses or domains to the whitelist or blacklist.
                Acceptable formats include:
                <ul>
                    <li><strong>Specific Email</strong>: <code>user@example.com</code></li>
                    <li><strong>Wildcard Domain</strong>: <code>*@example.com</code></li>
                    <li><strong>Partial Domain</strong> (subdomains included): <code>*@sub.example.com</code></li>
                </ul>
            </p>
            <form method="POST">
                <label for="list_type">List Type:</label>
                <select id="list_type" name="list_type" required>
                    <option value="blacklist">Blacklist</option>
                    <option value="whitelist">Whitelist</option>
                </select>

                <label for="email">Email or Domain:</label>
                <input type="text" id="email" name="email" placeholder="e.g., *@example.com" required>

                <button type="submit">Add to List</button>
            </form>
        </div>

        <!-- Display current whitelist entries with delete option -->
        <div class="box">
            <h2>Current Whitelist</h2>
            <?php if (!empty($whitelistEntries)): ?>
                <ul>
                    <?php foreach ($whitelistEntries as $entry): ?>
                        <li>
                            <?php echo htmlspecialchars($entry); ?> 
                            <a href="?delete=<?php echo urlencode($entry); ?>&type=whitelist&access_token=<?php echo urlencode($expectedToken); ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this whitelist entry?');">x</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No whitelist entries found.</p>
            <?php endif; ?>
        </div>

        <!-- Display current blacklist entries with delete option -->
        <div class="box">
            <h2>Current Blacklist</h2>
            <?php if (!empty($blacklistEntries)): ?>
                <ul>
                    <?php foreach ($blacklistEntries as $entry): ?>
                        <li>
                            <?php echo htmlspecialchars($entry); ?> 
                            <a href="?delete=<?php echo urlencode($entry); ?>&type=blacklist&access_token=<?php echo urlencode($expectedToken); ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this blacklist entry?');">x</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No blacklist entries found.</p>
            <?php endif; ?>
        </div>
    </div>

    <div style="text-align: center;">
        <a href="https://github.com/menached/black-white-lists" target="_PARENT">GitHub</a>
    </div>
</body>
</html>

