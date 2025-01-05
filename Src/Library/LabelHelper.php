<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Class LabelHelper
 *
 * This class provides helper functions for handling labels within the application.
 *
 * @package Library
 */
class LabelHelper
{

    /**
     * Creates labels based on the provided metadata, style, and categories.
     *
     * @param array $metadata An array of metadata used to create the labels.
     * @param string $style The style to be applied to the labels.
     * @param array $categories An array of categories to which the labels belong.
     *
     * @return array An array of created labels.
     */
    public function createLabels(array $metadata, string $style, array $categories): array
    {
        $labelService = new LabelService();
        $labelsToCreate = $labelService->loadFromConfig($categories);
        if ($labelsToCreate === null || count($labelsToCreate) === 0) {
            echo "⛔ No labels to create\n";
            return ["result" => -1];
        }

        $repositoryManager = new RepositoryManager();
        $existingLabels = $repositoryManager->getLabels($metadata["token"], $metadata["repositoryOwner"], $metadata["repositoryName"]);

        $labelsToUpdateObject = array();
        $labelsToCreate = array_filter($labelsToCreate, function ($label) use ($existingLabels, &$labelsToUpdateObject, $style) {
            $existingLabel = array_filter($existingLabels, function ($existingLabel) use ($label) {
                return strtolower($existingLabel["name"]) === strtolower($label["text"]) ||
                    strtolower($existingLabel["name"]) === strtolower($label["textWithIcon"]);
            });

            $total = count($existingLabel);

            if ($total > 0) {
                $existingLabel = array_values($existingLabel);
                $labelToUpdate = [];
                $labelToUpdate["color"] = substr($label["color"], 1);
                $labelToUpdate["description"] = $label["description"];
                $labelToUpdate["new_name"] = $style === "icons" ? $label["textWithIcon"] : $label["text"];
                $labelsToUpdateObject[$existingLabel[0]["name"]] = $labelToUpdate;
            }

            return $total === 0;
        });

        $labelsToCreateObject = array_map(function ($label) use ($style) {
            $newLabel = [];
            $newLabel["color"] = substr($label["color"], 1);
            $newLabel["description"] = $label["description"];
            $newLabel["name"] = $style === "icons" ? $label["textWithIcon"] : $label["text"];
            return $newLabel;
        }, $labelsToCreate);

        $totalLabelsToCreate = count($labelsToCreateObject);
        $totalLabelsToUpdate = count($labelsToUpdateObject);

        echo "🏷️ Total labels to create: {$totalLabelsToCreate}\n";
        echo "🏷️ Total labels to update: {$totalLabelsToUpdate}\n";
        echo "Styles: {$style}\n";
        echo "Categories: " . implode(", ", $categories) . "\n";

        if ($totalLabelsToCreate === 0 && $totalLabelsToUpdate === 0) {
            return ["result" => 0];
        }

        $labelService->processLabels($labelsToCreateObject, $labelsToUpdateObject, $metadata["token"], $metadata["labelsUrl"]);

        return ["result" => 1, "totalLabelsToCreate" => $totalLabelsToCreate, "totalLabelsToUpdate" => $totalLabelsToUpdate];
    }
}
