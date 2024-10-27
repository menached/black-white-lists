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
        $isDuplicate = in_array($email, $whitelistEntries) || in_array($email, $blacklistEntries);

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
    <title>Whitelist - Blacklist Manager</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: auto;
            padding: 20px;
            background-color: #f9f9f9;
        }
        h1 {
            color: #333;
        }
        form, .entries {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <h1>Whitelist / Blacklist Manager</h1>
    
    <!-- Form for adding entries -->
    <form method="POST">
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
        <label for="list_type">List Type:</label>
        <select id="list_type" name="list_type" required>
            <option value="blacklist">Blacklist</option>
            <option value="whitelist">Whitelist</option>
        </select>

        <label for="email">Email or Domain:</label>
        <input type="text" id="email" name="email" placeholder="e.g., *@example.com" required>

        <button type="submit">Add to List</button>
    </form>

    <!-- Display current whitelist entries with delete option -->
    <div class="entries">
        <h2>Current Whitelist</h2>
        <?php if (!empty($whitelistEntries)): ?>
            <ul>
                <?php foreach ($whitelistEntries as $entry): ?>
                    <li><?php echo htmlspecialchars($entry); ?> 
                        <a href="?delete=<?php echo urlencode($entry); ?>&type=whitelist&access_token=<?php echo urlencode($expectedToken); ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this whitelist entry?');">x</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No whitelist entries found.</p>
        <?php endif; ?>
    </div>

    <!-- Display current blacklist entries with delete option -->
    <div class="entries">
        <h2>Current Blacklist</h2>
        <?php if (!empty($blacklistEntries)): ?>
            <ul>
                <?php foreach ($blacklistEntries as $entry): ?>
                    <li><?php echo htmlspecialchars($entry); ?> 
                        <a href="?delete=<?php echo urlencode($entry); ?>&type=blacklist&access_token=<?php echo urlencode($expectedToken); ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this blacklist entry?');">x</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No blacklist entries found.</p>
        <?php endif; ?>
    </div>
<div style=text-align:center;><a href="https://github.com/menached/black-white-lists" target="_PARENT">GitHub</a></div>
</body>
</html>

