<?php

namespace GuiBranco\GStracciniBot\Library;

class LabelService
{
    public function loadFromConfig(array $categories): ?array
    {
        $fileNameLabels = "config/labels.json";
        $labels = array();

        if (file_exists($fileNameLabels)) {
            $rawLabels = file_get_contents($fileNameLabels);
            $labels = json_decode($rawLabels, true);
        } else {
            echo "⚠️ File {$fileNameLabels} not found!\n";
        }

        unset($labels["language"]);

        $keys = array_keys($labels);

        if (count($categories) > 1) {
            echo "🔍 Filtering by categories: ".join(",", $categories)."\n";
            $keys = array_intersect($keys, $categories);
        } elseif ($categories[0] !== "all") {
            echo "🔍 Filtering by category: ".$categories[0]."\n";
            $keys = array();
            if (in_array($categories, $keys)) {
                $keys = array($categories);
            }
        }

        if (count($keys) === 0) {
            echo "⛔ No filtered labels\n";
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
                echo "✅ Label created: {$label["name"]}\n";
            } elseif ($response->getStatusCode() === 422) {
                $labelToUpdate = [];
                $labelToUpdate["color"] = $label["color"];
                $labelToUpdate["description"] = $label["description"];
                $labelToUpdate["new_name"] = $label["name"];
                $labelsToUpdateObject[$label["name"]] = $labelToUpdate;
                echo "⚠️ Label already exists: {$label["name"]}\n";
            } else {
                echo "⛔ Error creating label: {$label["name"]}\n";
            }
        }

        foreach ($labelsToUpdateObject as $oldName => $label) {
            $response = doRequestGitHub($token, $labelsUrl . "/" . str_replace(" ", "%20", $oldName), $label, "PATCH");
            if ($response->getStatusCode() === 200) {
                echo "✅ Label updated: {$oldName} -> {$label["new_name"]}\n";
            } else {
                echo "⛔ Error updating label: {$oldName}\n";
            }
        }
    }
}
