<?php

namespace Database\Seeders\DemoData;

/** Curated examples; illustrative prices, never a live provider price feed. */
final class RealisticCatalog
{
    public static function products(): array
    {
        $groups = [
            'Rice & Grains' => [true, true, [
                'Tilda|Pure Basmati Rice 1kg|4.95', 'Tilda|Brown Basmati Rice 1kg|4.75',
                'Tilda|Jasmine Rice 1kg|4.50', 'Tilda|Long Grain Rice 1kg|3.95',
                'Goya|Yellow Corn Meal 680g|2.85', 'Quaker|Rolled Oats 1kg|3.85',
            ]],
            'Pulses, Flour & Baking' => [true, true, [
                'Goya|Red Lentils 500g|2.45', 'Goya|Chickpeas 500g|2.35',
                'Goya|Kidney Beans 500g|2.65', 'Aashirvaad|Whole Wheat Atta 5kg|9.50',
                'Aashirvaad|Multigrain Atta 5kg|10.95', 'Dr. Oetker|Baking Powder 170g|2.25',
            ]],
            'Cooking Oils & Seasonings' => [true, true, [
                'Fortune|Sunflower Oil 1L|3.25', 'Fortune|Rice Bran Oil 1L|3.75',
                'McCormick|Black Pepper 50g|3.45', 'McCormick|Garlic Powder 50g|2.95',
                'McCormick|Paprika 45g|2.85', 'McCormick|Vanilla Extract 59ml|5.50',
            ]],
            'Pasta, Sauces & Noodles' => [true, true, [
                'Barilla|Spaghetti 500g|2.45', 'Barilla|Penne Rigate 500g|2.45',
                'Barilla|Fusilli 500g|2.55', 'Barilla|Basilico Pasta Sauce 400g|3.50',
                'Maggi|Masala Noodles 4 Pack|2.45', 'Maggi|Chicken Noodles 4 Pack|2.45',
            ]],
            'Canned Food & Soup' => [true, true, [
                'Heinz|Baked Beans 415g|1.65', 'Heinz|Cream of Tomato Soup 400g|1.85',
                'Heinz|Chopped Tomatoes 400g|1.75', 'Green Giant|Sweet Corn 340g|1.95',
                'Green Giant|Garden Peas 400g|1.85', 'John West|Tuna Chunks 145g|2.75',
            ]],
            'Breakfast Cereals' => [true, true, [
                "Kellogg's|Corn Flakes 500g|4.50", "Kellogg's|Rice Krispies 510g|4.75",
                "Kellogg's|Coco Pops 480g|4.95", "Kellogg's|Special K Original 500g|5.25",
                'Quaker|Oat So Simple Original 10 Pack|3.95', 'Quaker|Granola Oats and Honey 500g|4.85',
            ]],
            'Spreads & Sweeteners' => [true, true, [
                'Nutella|Hazelnut Spread 350g|4.95', 'Nutella|Hazelnut Spread 750g|8.95',
                'Skippy|Creamy Peanut Butter 340g|3.95', 'Skippy|Crunchy Peanut Butter 340g|3.95',
                'Bonne Maman|Strawberry Preserve 370g|4.50', 'Bonne Maman|Apricot Preserve 370g|4.50',
            ]],
            'Tea, Coffee & Hot Drinks' => [true, true, [
                'Twinings|English Breakfast Tea 50 Bags|4.25', 'Twinings|Earl Grey Tea 50 Bags|4.25',
                'Nescafe|Gold Instant Coffee 200g|8.95', 'Nescafe|Classic Instant Coffee 200g|6.95',
                'Lavazza|Qualita Rossa Ground Coffee 250g|5.75', 'Cadbury|Drinking Chocolate 250g|3.95',
            ]],
            'Soft Drinks & Water' => [true, true, [
                'Coca-Cola|Original Cola 1.5L|2.25', 'Coca-Cola|Zero Sugar Cola 1.5L|2.25',
                'Coca-Cola|Sprite Lemon Lime 1.5L|2.20', 'Coca-Cola|Fanta Orange 1.5L|2.20',
                'Pepsi|Regular Cola 1.5L|2.15', 'Pepsi|Max Cola 1.5L|2.15',
            ]],
            'Juice & Energy Drinks' => [true, true, [
                'Tropicana|Orange Juice 1L|3.45', 'Tropicana|Apple Juice 1L|3.25',
                'Tropicana|Mango Juice 1L|3.45', 'Red Bull|Original Energy Drink 250ml|2.45',
                'Red Bull|Sugarfree Energy Drink 250ml|2.45', 'Red Bull|Original Energy Drink 4 Pack|8.95',
            ]],
            'Biscuits & Crackers' => [true, true, [
                'Oreo|Original Biscuits 154g|1.95', 'Oreo|Golden Biscuits 154g|1.95',
                'Oreo|Chocolate Creme Biscuits 154g|2.05', "McVitie's|Digestives 400g|2.65",
                "McVitie's|Chocolate Digestives 400g|3.15", 'Ritz|Original Crackers 200g|2.45',
            ]],
            'Chips & Savoury Snacks' => [true, true, [
                "Lay's|Classic Salted Chips 150g|2.25", "Lay's|Sour Cream and Onion Chips 150g|2.25",
                "Lay's|Barbecue Chips 150g|2.25", 'Doritos|Cool Original Chips 180g|2.75',
                'Doritos|Chilli Heatwave Chips 180g|2.75', "Haldiram's|Bhujia Sev 200g|2.20",
            ]],
            'Chocolate & Confectionery' => [true, true, [
                'Cadbury|Dairy Milk Chocolate 180g|3.45', 'Cadbury|Fruit and Nut Chocolate 180g|3.65',
                'Cadbury|Twirl Chocolate 4 Pack|2.95', 'Nestle|KitKat 4 Finger 4 Pack|2.95',
                'Nestle|Aero Milk Chocolate 90g|2.25', 'Mars|Snickers Chocolate 4 Pack|2.95',
            ]],
            'Oral & Body Care' => [true, true, [
                'Colgate|Total Toothpaste 100ml|3.25', 'Colgate|Max White Toothpaste 100ml|3.45',
                'Colgate|Medium Toothbrush 2 Pack|2.95', 'Dove|Beauty Bar 4 Pack|4.25',
                'Dove|Deeply Nourishing Body Wash 500ml|5.25', 'Dettol|Original Handwash 250ml|2.95',
            ]],
            'Hair Care & Grooming' => [true, true, [
                'Pantene|Repair and Protect Shampoo 400ml|5.45', 'Pantene|Smooth and Sleek Conditioner 400ml|5.45',
                'Pantene|Daily Moisture Shampoo 400ml|5.45', 'Nivea|Men Sensitive Shaving Gel 200ml|4.25',
                'Nivea|Soft Moisturising Cream 200ml|5.25', 'Gillette|Mach3 Razor|8.95',
            ]],
            'Laundry Care' => [true, false, [
                'Ariel|Original Washing Powder 2kg|8.50', 'Ariel|Colour Washing Powder 2kg|8.75',
                'Ariel|All-in-1 Laundry Pods 22 Pack|9.95', 'Comfort|Blue Skies Fabric Conditioner 1L|4.50',
                'Comfort|Pure Fabric Conditioner 1L|4.50', 'Comfort|Sunshine Fabric Conditioner 1L|4.50',
            ]],
            'Kitchen & Surface Cleaning' => [true, false, [
                'Finish|Dishwasher Tablets 30 Pack|9.95', 'Finish|Dishwasher Salt 1kg|3.25',
                'Cif|Cream Cleaner 500ml|2.95', 'Cif|Multi-Purpose Spray 700ml|3.45',
                'Dettol|Antibacterial Surface Cleaner 1L|4.25', 'Scotch-Brite|Heavy Duty Scrub Sponges 3 Pack|2.50',
            ]],
            'Paper & Household Supplies' => [false, false, [
                'Kleenex|Facial Tissues 100 Sheets|1.95', 'Kleenex|Balsam Tissues 64 Sheets|2.25',
                'Kleenex|Pocket Tissues 8 Pack|2.50', 'Ziploc|Freezer Bags 20 Pack|3.95',
                'Ziploc|Sandwich Bags 40 Pack|3.75', 'Duracell|AA Batteries 4 Pack|5.95',
            ]],
            'Kitchenware & Storage' => [false, false, [
                'Sistema|Lunch Box 1.2L|7.95', 'Sistema|Microwave Soup Mug 656ml|6.95',
                'Sistema|Klip It Storage Box 1L|5.95', 'Sistema|Hydrate Water Bottle 750ml|6.95',
                'Philips|Warm White LED Bulb 9W|3.95', 'Philips|Cool Daylight LED Bulb 12W|4.50',
            ]],
            'Stationery & School Supplies' => [false, false, [
                'Bic|Blue Ballpoint Pens 10 Pack|3.25', 'Bic|Black Ballpoint Pens 10 Pack|3.25',
                'Bic|Permanent Markers 4 Pack|4.25', 'Faber-Castell|HB Pencils 6 Pack|3.50',
                'Faber-Castell|Colour Pencils 12 Pack|4.95', 'Post-it|Yellow Notes 100 Sheets|2.50',
            ]],
        ];

        $rows = [];
        foreach ($groups as $category => [$batch, $expiry, $definitions]) {
            foreach ($definitions as $definition) {
                [$brand, $name, $price] = explode('|', $definition);
                $rows[] = compact('category', 'brand', 'name', 'price', 'batch', 'expiry');
            }
        }

        foreach ($rows as &$row) {
            $conversion = RealisticVariantConversions::forCatalog($row['brand'], $row['name']);
            if ($conversion) {
                $row['name'] = $conversion['name'];
                $row['attributes'] = $conversion['attributes'];
                $row['variant_multipliers'] = $conversion['multipliers'];
            }
        }
        unset($row);

        foreach ([
            ['Hanes', 'Cotton Crew Neck T-Shirt', '9.95', 'Apparel', 'Size', ['S', 'M', 'L', 'XL', 'XXL'], 'Color', ['Navy', 'White'], false],
            ['Hanes', 'Cotton Socks', '5.95', 'Apparel', 'Size', ['M', 'L', 'XL'], 'Pack', ['3 pairs', '6 pairs'], false],
            ['Sistema', 'Reusable Water Bottle', '6.95', 'Kitchenware & Storage', 'Capacity', ['500ml', '750ml', '1L'], 'Color', ['Blue', 'Green'], false],
            ['Lavazza', 'Qualita Oro Coffee Beans', '7.95', 'Tea, Coffee & Hot Drinks', 'Weight', ['250g', '500g', '1kg'], 'Roast', ['Medium', 'Dark'], true],
            ['Dove', 'Nourishing Body Wash', '4.50', 'Oral & Body Care', 'Size', ['250ml', '500ml', '750ml'], 'Scent', ['Original', 'Shea Butter'], true],
            ['Sistema', 'Food Storage Container', '5.95', 'Kitchenware & Storage', 'Capacity', ['500ml', '1L', '2L'], 'Color', ['Clear', 'Blue'], false],
        ] as [$brand, $name, $price, $category, $a, $av, $b, $bv, $perishable]) {
            $rows[] = compact('brand', 'name', 'price', 'category') + [
                'batch' => $perishable,
                'expiry' => $perishable,
                'attributes' => [['name' => $a, 'values' => $av], ['name' => $b, 'values' => $bv]],
            ];
        }

        return $rows;
    }
}
