<?php
$files = array_merge(glob("*.php"), glob("includes/*.php"), glob("pages/*.php"));
$tables = ["experiment_class", "experiment_device", "media"];

foreach ($files as $file) {
    if (in_array($file, ['fix.php', 'fix_arrays.php', 'fix_syntax.php', 'prefixer.php', 'apply_prefixes.php'])) continue;
    $content = file_get_contents($file);
    $original = $content;
    
    foreach ($tables as $table) {
        // Find instances where the table is used in a query string and isn't already prefixed
        // Add " . DB_PREFIX . " in front of it. We'll handle cleanup below.
        $content = preg_replace("/\b(?<!DB_PREFIX \. \")(?<!DB_PREFIX \. \')(?<!LO_)(" . preg_quote($table, '/') . ")\b/i", "\" . DB_PREFIX . \"$1", $content);
    }
    
    // Clean up ugly quoting if it was inside an existing double-quoted string
    $content = str_replace('"" . DB_PREFIX . "', '" . DB_PREFIX . "', $content);
    $content = str_replace('"" . DB_PREFIX . ""', '". DB_PREFIX ."', $content);
    
    // In setup.php we have: CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "experiment_device (
    // Instead of doing complex regex, I know exactly which rows are in setup.php and experiments.php.

    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "Prefixed tables in: $file\n";
    }
}
?>
