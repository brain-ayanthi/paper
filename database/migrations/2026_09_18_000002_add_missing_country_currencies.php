<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Missing active country/territory currencies from SIX ISO 4217 List One (2026-09-17).
 * https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml
 * Fund, precious-metal, test and no-currency codes are excluded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['code' => 'AFN', 'name' => 'Afghan Afghani', 'symbol' => 'AFN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'ALL', 'name' => 'Albanian Lek', 'symbol' => 'ALL', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'AMD', 'name' => 'Armenian Dram', 'symbol' => 'AMD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'AOA', 'name' => 'Angolan Kwanza', 'symbol' => 'AOA', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'AWG', 'name' => 'Aruban Florin', 'symbol' => 'AWG', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'AZN', 'name' => 'Azerbaijan Manat', 'symbol' => 'AZN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BAM', 'name' => 'Bosnia and Herzegovina Convertible Mark', 'symbol' => 'BAM', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BBD', 'name' => 'Barbados Dollar', 'symbol' => 'BBD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BIF', 'name' => 'Burundi Franc', 'symbol' => 'BIF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BMD', 'name' => 'Bermudian Dollar', 'symbol' => 'BMD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BND', 'name' => 'Brunei Dollar', 'symbol' => 'BND', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BOB', 'name' => 'Bolivian Boliviano', 'symbol' => 'BOB', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BSD', 'name' => 'Bahamian Dollar', 'symbol' => 'BSD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BTN', 'name' => 'Bhutanese Ngultrum', 'symbol' => 'BTN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BWP', 'name' => 'Botswana Pula', 'symbol' => 'BWP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BYN', 'name' => 'Belarusian Ruble', 'symbol' => 'BYN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'BZD', 'name' => 'Belize Dollar', 'symbol' => 'BZD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'CDF', 'name' => 'Congolese Franc', 'symbol' => 'CDF', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'CRC', 'name' => 'Costa Rican Colon', 'symbol' => 'CRC', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'CUP', 'name' => 'Cuban Peso', 'symbol' => 'CUP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'CVE', 'name' => 'Cabo Verde Escudo', 'symbol' => 'CVE', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'DJF', 'name' => 'Djibouti Franc', 'symbol' => 'DJF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'DOP', 'name' => 'Dominican Peso', 'symbol' => 'DOP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'DZD', 'name' => 'Algerian Dinar', 'symbol' => 'DZD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'ERN', 'name' => 'Eritrean Nakfa', 'symbol' => 'ERN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'ETB', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'FJD', 'name' => 'Fiji Dollar', 'symbol' => 'FJD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'FKP', 'name' => 'Falkland Islands Pound', 'symbol' => 'FKP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'GEL', 'name' => 'Georgian Lari', 'symbol' => 'GEL', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'GIP', 'name' => 'Gibraltar Pound', 'symbol' => 'GIP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'GMD', 'name' => 'Gambian Dalasi', 'symbol' => 'GMD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'GNF', 'name' => 'Guinean Franc', 'symbol' => 'GNF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'GTQ', 'name' => 'Guatemalan Quetzal', 'symbol' => 'GTQ', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'GYD', 'name' => 'Guyana Dollar', 'symbol' => 'GYD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'HNL', 'name' => 'Honduran Lempira', 'symbol' => 'HNL', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'HTG', 'name' => 'Haitian Gourde', 'symbol' => 'HTG', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'HUF', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'IQD', 'name' => 'Iraqi Dinar', 'symbol' => 'IQD', 'decimals' => 3, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'IRR', 'name' => 'Iranian Rial', 'symbol' => 'IRR', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'ISK', 'name' => 'Iceland Krona', 'symbol' => 'ISK', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'JMD', 'name' => 'Jamaican Dollar', 'symbol' => 'JMD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'KGS', 'name' => 'Kyrgyzstani Som', 'symbol' => 'KGS', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'KHR', 'name' => 'Cambodian Riel', 'symbol' => 'KHR', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'KMF', 'name' => 'Comorian Franc', 'symbol' => 'KMF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'KPW', 'name' => 'North Korean Won', 'symbol' => 'KPW', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'KYD', 'name' => 'Cayman Islands Dollar', 'symbol' => 'KYD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'KZT', 'name' => 'Kazakhstani Tenge', 'symbol' => 'KZT', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'LAK', 'name' => 'Lao Kip', 'symbol' => 'LAK', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'LBP', 'name' => 'Lebanese Pound', 'symbol' => 'LBP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'LRD', 'name' => 'Liberian Dollar', 'symbol' => 'LRD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'LSL', 'name' => 'Lesotho Loti', 'symbol' => 'LSL', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'LYD', 'name' => 'Libyan Dinar', 'symbol' => 'LYD', 'decimals' => 3, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MDL', 'name' => 'Moldovan Leu', 'symbol' => 'MDL', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MGA', 'name' => 'Malagasy Ariary', 'symbol' => 'MGA', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MKD', 'name' => 'Macedonian Denar', 'symbol' => 'MKD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MMK', 'name' => 'Myanmar Kyat', 'symbol' => 'MMK', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MNT', 'name' => 'Mongolian Tugrik', 'symbol' => 'MNT', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MOP', 'name' => 'Macanese Pataca', 'symbol' => 'MOP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MRU', 'name' => 'Mauritanian Ouguiya', 'symbol' => 'MRU', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MUR', 'name' => 'Mauritius Rupee', 'symbol' => 'MUR', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MVR', 'name' => 'Maldivian Rufiyaa', 'symbol' => 'MVR', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MWK', 'name' => 'Malawi Kwacha', 'symbol' => 'MWK', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'MZN', 'name' => 'Mozambique Metical', 'symbol' => 'MZN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'NAD', 'name' => 'Namibia Dollar', 'symbol' => 'NAD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'NIO', 'name' => 'Nicaraguan Cordoba', 'symbol' => 'NIO', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'PAB', 'name' => 'Panamanian Balboa', 'symbol' => 'PAB', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'PGK', 'name' => 'Papua New Guinean Kina', 'symbol' => 'PGK', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'PYG', 'name' => 'Paraguayan Guarani', 'symbol' => 'PYG', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'RON', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'RSD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'RWF', 'name' => 'Rwanda Franc', 'symbol' => 'RWF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SBD', 'name' => 'Solomon Islands Dollar', 'symbol' => 'SBD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SCR', 'name' => 'Seychelles Rupee', 'symbol' => 'SCR', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SDG', 'name' => 'Sudanese Pound', 'symbol' => 'SDG', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SHP', 'name' => 'Saint Helena Pound', 'symbol' => 'SHP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SLE', 'name' => 'Sierra Leonean Leone', 'symbol' => 'SLE', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SOS', 'name' => 'Somali Shilling', 'symbol' => 'SOS', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SRD', 'name' => 'Surinam Dollar', 'symbol' => 'SRD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SSP', 'name' => 'South Sudanese Pound', 'symbol' => 'SSP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'STN', 'name' => 'Sao Tome and Principe Dobra', 'symbol' => 'STN', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SVC', 'name' => 'El Salvador Colon', 'symbol' => 'SVC', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SYP', 'name' => 'Syrian Pound', 'symbol' => 'SYP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'SZL', 'name' => 'Eswatini Lilangeni', 'symbol' => 'SZL', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'TJS', 'name' => 'Tajikistani Somoni', 'symbol' => 'TJS', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'TMT', 'name' => 'Turkmenistan New Manat', 'symbol' => 'TMT', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'TND', 'name' => 'Tunisian Dinar', 'symbol' => 'TND', 'decimals' => 3, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'TOP', 'name' => 'Tongan Paanga', 'symbol' => 'TOP', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'TTD', 'name' => 'Trinidad and Tobago Dollar', 'symbol' => 'TTD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TZS', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'symbol' => 'UAH', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'UGX', 'name' => 'Uganda Shilling', 'symbol' => 'UGX', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'UYU', 'name' => 'Uruguayan Peso', 'symbol' => 'UYU', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'UZS', 'name' => 'Uzbekistan Sum', 'symbol' => 'UZS', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'VED', 'name' => 'Venezuelan Bolivar (VED)', 'symbol' => 'VED', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'VES', 'name' => 'Venezuelan Bolivar (VES)', 'symbol' => 'VES', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'VUV', 'name' => 'Vanuatu Vatu', 'symbol' => 'VUV', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'WST', 'name' => 'Samoan Tala', 'symbol' => 'WST', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'XAF', 'name' => 'Central African CFA Franc', 'symbol' => 'XAF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'XCD', 'name' => 'East Caribbean Dollar', 'symbol' => 'XCD', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'XCG', 'name' => 'Caribbean Guilder', 'symbol' => 'XCG', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'XOF', 'name' => 'West African CFA Franc', 'symbol' => 'XOF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'XPF', 'name' => 'CFP Franc', 'symbol' => 'XPF', 'decimals' => 0, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'YER', 'name' => 'Yemeni Rial', 'symbol' => 'YER', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha', 'symbol' => 'ZMW', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
            ['code' => 'ZWG', 'name' => 'Zimbabwe Gold', 'symbol' => 'ZWG', 'decimals' => 2, 'symbol_first' => true, 'thousands_separator' => ',', 'decimal_separator' => '.'],
        ];

        foreach ($rows as $row) {
            // Preserve customer formats and the active status of existing currencies.
            if (! DB::table('currencies')->where('code', $row['code'])->exists()) {
                DB::table('currencies')->insert($row + ['is_active' => true]);
            }
        }
    }

    public function down(): void
    {
        // Retain reference data used by settings or historical transactions.
    }
};
