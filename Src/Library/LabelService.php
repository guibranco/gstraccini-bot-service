<?php

namespace GuiBranco\GStracciniBot\Library;

class LabelService
{
    use LogsToStream;

    private const LOG_CHANNEL = "labels";

    public function loadFromConfig(array $categories): ?array
    {
        $fileNameLabels = "config/labels.json";
        $labels = array();

        if (file_exists($fileNameLabels)) {
            $rawLabels = file_get_contents($fileNameLabels);
            $labels = json_decode($rawLabels, true);
        } else {
            $this->logToStream("error", 'Labels file {file} not found', ["file" => $fileNameLabels]);
        }

        unset($labels["language"], $labels["package_managers"]);

        $keys = array_keys($labels);

        if (count($categories) > 1) {
            $this->logToStream("debug", 'Filtering labels by categories: {categories}', ["categories" => implode(", ", $categories)]);
            $keys = array_intersect($keys, $categories);
        } elseif ($categories[0] !== "all") {
            $this->logToStream("debug", 'Filtering labels by category: {category}', ["category" => $categories[0]]);
            $keys = array();
            if (in_array($categories, $keys)) {
                $keys = array($categories);
            }
        }

        if (count($keys) === 0) {
            $this->logToStream("notice", 'No labels left after filtering by {categories}', ["categories" => implode(", ", $categories)]);
            return null;
        }

        $finalLabels = array();
        foreach ($keys as $key) {
            $finalLabels = array_merge($finalLabels, $labels[$key]);
        }

        return $finalLabels;
    }

    public function processLabels(array $labelsToCreateObject, array $labelsToUpdateObject, string $token, string $labelsUrl): void
    {
        foreach ($labelsToCreateObject as $label) {
            $response = doRequestGitHub($token, $labelsUrl, $label, "POST");
            if ($response->getStatusCode() === 201) {
                $this->logToStream("debug", 'Label {label} created', ["label" => $label["name"], "url" => $labelsUrl]);
            } elseif ($response->getStatusCode() === 422) {
                $labelToUpdate = [];
                $labelToUpdate["color"] = $label["color"];
                $labelToUpdate["description"] = $label["description"];
                $labelToUpdate["new_name"] = $label["name"];
                $labelsToUpdateObject[$label["name"]] = $labelToUpdate;
                $this->logToStream("notice", 'Label {label} already exists — updating it instead', ["label" => $label["name"], "url" => $labelsUrl]);
            } else {
                $this->logToStream("error", 'Failed to create label {label} (HTTP {status})', ["label" => $label["name"], "url" => $labelsUrl, "status" => $response->getStatusCode(), "body" => $response->getBody()]);
            }
        }

        foreach ($labelsToUpdateObject as $oldName => $label) {
            $response = doRequestGitHub($token, $labelsUrl . "/" . str_replace(" ", "%20", $oldName), $label, "PATCH");
            if ($response->getStatusCode() === 200) {
                $this->logToStream("debug", 'Label {label} updated to {newName}', ["label" => $oldName, "newName" => $label["new_name"], "url" => $labelsUrl]);
            } else {
                $this->logToStream("error", 'Failed to update label {label} (HTTP {status})', ["label" => $oldName, "url" => $labelsUrl, "status" => $response->getStatusCode(), "body" => $response->getBody()]);
            }
        }
    }
}
