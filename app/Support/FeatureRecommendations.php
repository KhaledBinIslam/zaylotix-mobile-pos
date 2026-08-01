<?php

namespace App\Support;

/**
 * Reads the `features` list each business type in config/business_types.php
 * recommends, and inverts it into a per-feature-key -> business-type-labels
 * map — shown as a note on the admin Features page ("Recommended for:
 * Grocery, General") and used to pre-check the Create Shop form's feature
 * checkboxes when a business type is picked. Purely advisory: admin can
 * still grant/withhold anything regardless of what's recommended here.
 */
class FeatureRecommendations
{
    /** @return array<string, string[]> feature key => business type English labels */
    public static function byFeatureKey(): array
    {
        $map = [];

        foreach (config('business_types', []) as $type) {
            foreach ($type['features'] ?? [] as $featureKey) {
                $map[$featureKey][] = $type['label_en'];
            }
        }

        return $map;
    }

    /** @return string[] feature keys recommended for this business type slug */
    public static function forBusinessType(?string $slug): array
    {
        return $slug ? (config("business_types.{$slug}.features") ?? []) : [];
    }
}
