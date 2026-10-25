<?php

namespace Database\Seeders\DemoData;

final class RealisticVariantConversions
{
    /** @return list<array<string, mixed>> */
    public static function products(): array
    {
        return [
            self::product('DEMO-GOYA-007', 'Goya', 'Red Lentils 500g', 'Red Lentils', 'Weight', ['500g', '1kg'], 'Pack', ['Single', '3 Pack'], ['1', '2.70', '1.80', '5.00']),
            self::product('DEMO-FORTUNE-013', 'Fortune', 'Sunflower Oil 1L', 'Sunflower Oil', 'Volume', ['1L', '5L'], 'Pack', ['Single', '2 Pack'], ['1', '1.90', '4.50', '8.50']),
            self::product('DEMO-BARILLA-019', 'Barilla', 'Spaghetti 500g', 'Spaghetti', 'Weight', ['500g', '1kg'], 'Pack', ['Single', '2 Pack'], ['1', '1.90', '1.80', '3.40']),
            self::product('DEMO-MAGGI-023', 'Maggi', 'Masala Noodles 4 Pack', 'Instant Noodles', 'Pack Size', ['4 Pack', '8 Pack'], 'Flavor', ['Masala', 'Spicy Masala'], ['1', '1.08', '1.90', '2.05']),
            self::product('DEMO-HEINZ-025', 'Heinz', 'Baked Beans 415g', 'Baked Beans', 'Size', ['415g', '800g'], 'Pack', ['Single', '4 Pack'], ['1', '3.70', '1.80', '6.60']),
            self::product('DEMO-KELLOGGS-031', "Kellogg's", 'Corn Flakes 500g', 'Corn Flakes', 'Size', ['500g', '1kg'], 'Pack', ['Single', '2 Pack'], ['1', '1.90', '1.85', '3.50']),
            self::product('DEMO-NUTELLA-037', 'Nutella', 'Hazelnut Spread 350g', 'Hazelnut Spread', 'Size', ['350g', '750g'], 'Pack', ['Single', '2 Pack'], ['1', '1.90', '1.80', '3.40']),
            self::product('DEMO-TWININGS-043', 'Twinings', 'English Breakfast Tea 50 Bags', 'English Breakfast Tea', 'Count', ['50 Bags', '100 Bags'], 'Pack', ['Single', '2 Pack'], ['1', '1.90', '1.80', '3.40']),
            self::product('DEMO-NESCAFE-045', 'Nescafe', 'Gold Instant Coffee 200g', 'Gold Instant Coffee', 'Size', ['100g', '200g'], 'Strength', ['Smooth', 'Intense'], ['0.58', '0.63', '1', '1.08']),
            self::product('DEMO-COCA-COLA-049', 'Coca-Cola', 'Original Cola 1.5L', 'Original Cola', 'Size', ['500ml', '1.5L'], 'Pack', ['Single', '6 Pack'], ['0.45', '2.40', '1', '5.30']),
            self::product('DEMO-TROPICANA-055', 'Tropicana', 'Orange Juice 1L', 'Orange Juice', 'Size', ['250ml', '1L'], 'Pack', ['Single', '6 Pack'], ['0.38', '2.05', '1', '5.35']),
            self::product('DEMO-OREO-061', 'Oreo', 'Original Biscuits 154g', 'Original Biscuits', 'Size', ['154g', '300g'], 'Pack', ['Single', '3 Pack'], ['1', '2.75', '1.80', '5.00']),
            self::product('DEMO-LAYS-067', "Lay's", 'Classic Salted Chips 150g', 'Potato Chips', 'Size', ['50g', '150g'], 'Pack', ['Single', 'Multipack'], ['0.42', '1.55', '1', '3.60']),
            self::product('DEMO-CADBURY-073', 'Cadbury', 'Dairy Milk Chocolate 180g', 'Dairy Milk Chocolate', 'Size', ['90g', '180g'], 'Pack', ['Single', '3 Pack'], ['0.55', '1.50', '1', '2.75']),
            self::product('DEMO-COLGATE-079', 'Colgate', 'Total Toothpaste 100ml', 'Total Toothpaste', 'Size', ['100ml', '200ml'], 'Pack', ['Single', '2 Pack'], ['1', '1.90', '1.80', '3.40']),
            self::product('DEMO-DETTOL-084', 'Dettol', 'Original Handwash 250ml', 'Handwash', 'Size', ['250ml', '500ml'], 'Scent', ['Original', 'Aloe'], ['1', '1.05', '1.75', '1.84']),
            self::product('DEMO-PANTENE-085', 'Pantene', 'Repair and Protect Shampoo 400ml', 'Repair and Protect Shampoo', 'Size', ['200ml', '400ml'], 'Pack', ['Single', '2 Pack'], ['0.58', '1.10', '1', '1.90']),
            self::product('DEMO-ARIEL-091', 'Ariel', 'Original Washing Powder 2kg', 'Original Washing Powder', 'Weight', ['1kg', '2kg'], 'Pack', ['Single', '2 Pack'], ['0.55', '1.05', '1', '1.90']),
        ];
    }

    /** @return array<string, mixed> */
    private static function product(
        string $sku,
        string $brand,
        string $oldName,
        string $name,
        string $firstName,
        array $firstValues,
        string $secondName,
        array $secondValues,
        array $multipliers,
    ): array {
        return [
            'sku' => $sku,
            'brand' => $brand,
            'old_name' => $oldName,
            'name' => $name,
            'attributes' => [
                ['name' => $firstName, 'values' => $firstValues],
                ['name' => $secondName, 'values' => $secondValues],
            ],
            'multipliers' => $multipliers,
        ];
    }

    public static function forCatalog(string $brand, string $name): ?array
    {
        foreach (self::products() as $row) {
            if ($row['brand'] === $brand && $row['old_name'] === $name) {
                return $row;
            }
        }

        return null;
    }
}
