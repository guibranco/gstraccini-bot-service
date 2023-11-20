<?php 

function connectToDatabase()
{
    global $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase;

    $mysqli = new mysqli($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
    if ($mysqli->connect_errno) {
        die("Failed to connect to MySQL: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");

    return $mysqli;
}

function readTable($tableName, $where = null){
    $mysqli = connectToDatabase();
    $defaultWhere = "Processed = 0 ORDER BY Sequence ASC LIMIT 10";
    $sql = "SELECT * FROM " . $tableName . " WHERE ";
    if($where == null){
        $sql .= $defaultWhere;
    } else {
        $sql .= $where;
    }
    $result = $mysqli->query($sql);

    if (!$result) {
        return null;
    }

    $data = array();

    while ($obj = $result->fetch_object()) {
        $data[] = $obj;
    }

    $result->close();
    $mysqli->close();

    return $data;
}

function updateTable($tableName, $sequence) {
    $mysqli = connectToDatabase();
    $sql = "UPDATE " . $tableName . " SET Processed = 1, ProcessedDate = NOW() WHERE Sequence = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $sequence);

    $stmt->execute();
    $stmt->close();
    $mysqli->close();
}
