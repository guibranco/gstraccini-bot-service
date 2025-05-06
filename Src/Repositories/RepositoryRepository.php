<?php

namespace GuiBranco\GStracciniBot\Repositories;

use GuiBranco\GStracciniBot\Library\Database;

class RepositoryRepository implements IRepository
{
    private const TABLE_NAME = "github_repositories";
    private $connection;

    public function __construct()
    {
        $this->connection = connectToDatabase();
    }

    public function getAll(): array
    {
        $sql = "SELECT * FROM `" . self::TABLE_NAME . "`";
        $result = $this->connection->query($sql);

        $matches = array();

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    public function getById($id): ?object
    {
        $sql = "SELECT * FROM `" . self::TABLE_NAME . "` WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_object();
            $stmt->close();
            return $row;
        }

        $stmt->close();
        return null;
    }

    public function upsert($entity): int
    {
        $id = $entity->espn_id;
        $query = "SELECT 1 FROM `" . self::TABLE_NAME . "` WHERE `espn_id` = ?";
        $stmt = $this->connection->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        $fields = $this->getFields();
        $questionMarks = array_fill(0, count($fields), "?");
        $params = "";
        $fieldsQuery = array();
        foreach ($fields as $key => $value) {
            $fieldsQuery[] = $key . " = ?";
            $params .= $value;
        }

        if ($result->num_rows === 0) {
            $sql = "INSERT INTO `" . self::TABLE_NAME . "` (" . implode(",", array_keys($fields)) . ")";
            $sql .= "VALUES (" . implode(",", $questionMarks) . ")";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                $params,
                $entity->league_id,
                $entity->home_team_id,
                $entity->away_team_id,
                $entity->home_team_score,
                $entity->away_team_score,
                $entity->espn_id,
                $entity->espn_route,
                $entity->venue_name,
                $entity->venue_city,
                $entity->venue_country,
                $entity->date,
                $entity->status,
            );
        } else {
            $sql = "UPDATE `" . self::TABLE_NAME . "` SET ";
            $sql .= implode(", ", $fieldsQuery);
            $sql .= ", updated_at = current_timestamp() WHERE espn_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                $params . "i",
                $entity->league_id,
                $entity->home_team_id,
                $entity->away_team_id,
                $entity->home_team_score,
                $entity->away_team_score,
                $entity->espn_id,
                $entity->espn_route,
                $entity->venue_name,
                $entity->venue_city,
                $entity->venue_country,
                $entity->date,
                $entity->status,
                $entity->espn_id,
            );
        }

        $stmt->execute();
        $stmt->close();

        return $this->connection->insert_id;
    }

    public function delete($id): void
    {
        $sql = "DELETE FROM `" . self::TABLE_NAME . "` WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }

    private function getFields()
    {
        return array(
            "league_id" => "i",
            "home_team_id" => "i",
            "away_team_id" => "i",
            "home_team_score" => "i",
            "away_team_score" => "i",
            "espn_id" => "i",
            "espn_route" => "s",
            "venue_name" => "s",
            "venue_city" => "s",
            "venue_country" => "s",
            "date" => "s",
            "status" => "s"
        );
    }
}