<?php
// product/required/product-data.php
// Centralized source of truth for product and discount information, matching the sales spreadsheet.

// Product data matching the spreadsheet exactly
$products = [
    [
        'name' => '32/20 Blend',
        'total_mg' => 640,
        'mg_per_oz' => 20,
        'bottle_size' => 32,
        'msrp' => 85.02,
        'sale_price' => 79.95, // Matches sales reference sheet
        'sku' => 'BLEND-32-20',
        'servings' => 64,
        'serving_size_oz' => 0.5
    ],
    [
        'name' => '128/20 Blend', 
        'total_mg' => 2560,
        'mg_per_oz' => 20,
        'bottle_size' => 128,
        'msrp' => 186.10,
        'sale_price' => 179.95, // Matches sales reference sheet
        'sku' => 'BLEND-128-20',
        'servings' => 256,
        'serving_size_oz' => 0.25
    ],
    [
        'name' => '32/75 Blend',
        'total_mg' => 2400,
        'mg_per_oz' => 75,
        'bottle_size' => 32,
        'msrp' => 174.40,
        'sale_price' => 169.95, // Matches sales reference sheet
        'sku' => 'BLEND-32-75',
        'servings' => 240,
        'serving_size_oz' => 0.50
    ],
    [
        'name' => '128/75 Blend',
        'total_mg' => 9600,
        'mg_per_oz' => 75,
        'bottle_size' => 128,
        'msrp' => 528.50,
        'sale_price' => 519.95, // Matches sales reference sheet
        'sku' => 'BLEND-128-75',
        'servings' => 960,
        'serving_size_oz' => 0.25
    ]
];

// Bulk discount structure
$bulk_discounts = [
    ['qty' => 'X5', 'discount' => 5],
    ['qty' => 'X10', 'discount' => 8], 
    ['qty' => 'X15', 'discount' => 11],
    ['qty' => 'X20', 'discount' => 15]
];
