<?php
require_once '../admin/class/Db.class.php';

$db = new Db();

if ($db->getConn()->errorCode() != 0) {
    die('Connection Error (' . $db->getConn()->errorCode() . ') ' . $db->getConn()->errorInfo());
}

$sqlFile = file_get_contents('aigency.sql');

$queries = explode(';', $sqlFile);

$createQueries = array();
$insertQuery = '';
$executedQueries = '';  // Initialize the variable to store executed queries

foreach ($queries as $query) {
    $query = trim($query);

    if (substr($query, 0, 12) == 'CREATE TABLE') {
        $createQueries[] = $query;
    }

    if (strpos($query, 'INSERT INTO `badwords`') !== false) {
        $insertQuery = $query;
    }
}

foreach ($createQueries as $query) {
    //echo $query . "<br>\n";
    preg_match("/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/", $query, $tableNameMatches);

    if (!isset($tableNameMatches[1])) {
        echo "Failed to extract table name from query: $query\n<br><hr>";
        continue;
    }
    
    $tableName = $tableNameMatches[1];

    if ($db->getConn()->query($query) === FALSE) {
        echo "Error creating table $tableName: " . $db->getConn()->errorInfo()[2] . "\n<br><hr>";
    } else {
        echo "Successfully created table $tableName.\n<br><hr>";
        $executedQueries .= $query . ";\n";  // Add the query to the list of executed queries
    }

    preg_match_all("/`([^`]+)` [^,]+(,|$)/", $query, $fieldMatches);
    $fields = $fieldMatches[1];

    $result = $db->getConn()->query("SHOW COLUMNS FROM `$tableName`");
    if (!$result) {
        echo "Failed to fetch columns from table $tableName: " . $db->getConn()->errorInfo()[2] . "\n<br><hr>";
        continue;
    }
    
    $currentFields = [];
    while ($row = $result->fetch()) {
        $currentFields[] = $row['Field'];
    }

    $fieldsToAdd = array_diff($fields, $currentFields);

    foreach ($fieldsToAdd as $fieldToAdd) {
        if ($fieldToAdd === $tableName) {
            continue;
        }

        preg_match("/`$fieldToAdd` ([^,]+)(,|$)/", $query, $fieldDefinitionMatches);
        if (!isset($fieldDefinitionMatches[1])) {
            echo "Failed to extract field definition for $fieldToAdd from query: $query\n<br><hr>";
            continue;
        }
        
        $fieldDefinition = $fieldDefinitionMatches[1];

        if ($db->getConn()->query("ALTER TABLE `$tableName` ADD `$fieldToAdd` $fieldDefinition") === FALSE) {
            echo "Error adding field $fieldToAdd to table $tableName: " . $db->getConn()->errorInfo()[2] . "\n<br><hr>";
        } else {
            echo "Successfully added field $fieldToAdd with definition $fieldDefinition to table $tableName.\n<br><hr>";
        }
    }
}

$result = $db->getConn()->query("SELECT COUNT(*) AS count FROM `badwords`");
if ($result) {
    $row = $result->fetch();
    $count = $row['count'];
    if ($count > 0) {
        echo "badwords updated.\n<br><hr>";
    } else {
        if (!empty($insertQuery)) {
            echo $insertQuery . "<br>\n";
            if ($db->getConn()->exec($insertQuery) === FALSE) {
                echo "Error inserting data into the 'badwords' table: " . $db->getConn()->errorInfo()[2] . "\n<br><hr>";
            } else {
                echo "Successfully inserted data into the 'badwords' table.\n<br><hr>";
                $executedQueries .= $insertQuery . ";\n";  // Add the query to the list of executed queries
            }
        }
    }
} else {
    echo "Error checking the 'badwords' table: " . $db->getConn()->errorInfo()[2] . "\n<br><hr>";
}
?>