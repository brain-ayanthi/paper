<?php

namespace Database\Seeders\DemoData;

final class RealisticPharmacyCatalog
{
    /** @return list<array<string, string>> */
    public static function products(): array
    {
        $groups = [
            'Pain Relief & Fever' => ['OTC', [
                'Panadol|Panadol 500mg 15 Tablets|Paracetamol 500mg|GSK|2.25',
                'Brufen|Brufen 400mg 15 Tablets|Ibuprofen 400mg|Abbott|3.40',
                'Disprin|Disprin 325mg 10 Tablets|Aspirin 325mg|Reckitt|1.50',
                'Voveran|Voveran 50mg 10 Tablets|Diclofenac sodium 50mg|Novartis|4.10',
                'Aleve|Aleve 220mg 12 Tablets|Naproxen sodium 220mg|Bayer|5.25',
                'Combiflam|Combiflam 20 Tablets|Ibuprofen + Paracetamol|Sanofi|4.75',
            ]],
            'Antibiotics' => ['H', [
                'Cipmox|Cipmox 500mg 10 Capsules|Amoxicillin 500mg|Cipla|6.80',
                'Azee|Azee 500mg 3 Tablets|Azithromycin 500mg|Cipla|8.50',
                'Cifran|Cifran 500mg 10 Tablets|Ciprofloxacin 500mg|Sun Pharma|7.25',
                'Doxy-1|Doxy-1 100mg 10 Capsules|Doxycycline 100mg|USV|5.40',
                'Sporidex|Sporidex 500mg 10 Capsules|Cephalexin 500mg|Sun Pharma|9.75',
                'Flagyl|Flagyl 400mg 15 Tablets|Metronidazole 400mg|Abbott|4.25',
            ]],
            'Digestive Health' => ['OTC', [
                'Pantocid|Pantocid 40mg 15 Tablets|Pantoprazole 40mg|Sun Pharma|6.50',
                'Omez|Omez 20mg 15 Capsules|Omeprazole 20mg|Dr. Reddy\'s|5.25',
                'Digene|Digene Mint Gel 200ml|Antacid suspension|Abbott|4.60',
                'ENO|ENO Lemon Fruit Salt 100g|Sodium bicarbonate + citric acid|GSK|3.10',
                'Electral|Electral ORS Powder 21.8g|Oral rehydration salts|FDC|1.20',
            ]],
            'Cough, Cold & Allergy' => ['OTC', [
                'Cetzine|Cetzine 10mg 10 Tablets|Cetirizine 10mg|GSK|2.40',
                'Allegra|Allegra 120mg 10 Tablets|Fexofenadine 120mg|Sanofi|7.50',
                'Benadryl|Benadryl Cough Formula 100ml|Diphenhydramine|Johnson & Johnson|4.90',
                'Vicks|Vicks Action 500 Advanced 10 Tablets|Paracetamol + Phenylephrine|P&G|3.20',
                'Strepsils|Strepsils Orange 8 Lozenges|Amylmetacresol + Dichlorobenzyl alcohol|Reckitt|2.75',
            ]],
            'Cardiac & Diabetes Care' => ['H', [
                'Lipitor|Lipitor 10mg 10 Tablets|Atorvastatin 10mg|Pfizer|8.25',
                'Norvasc|Norvasc 5mg 10 Tablets|Amlodipine 5mg|Pfizer|6.40',
                'Telma|Telma 40mg 15 Tablets|Telmisartan 40mg|Glenmark|7.80',
                'Clopilet|Clopilet 75mg 15 Tablets|Clopidogrel 75mg|Sun Pharma|9.20',
                'Glycomet|Glycomet 500mg 20 Tablets|Metformin 500mg|USV|4.10',
                'Lantus|Lantus 100IU/ml 10ml Vial|Insulin glargine|Sanofi|42.50',
            ]],
            'Vitamins & Supplements' => ['OTC', [
                'Uprise-D3|Uprise-D3 60000IU 4 Capsules|Cholecalciferol 60000IU|Alkem|5.75',
                'Limcee|Limcee 500mg 15 Tablets|Ascorbic acid 500mg|Abbott|3.50',
                'Becosules|Becosules 20 Capsules|Vitamin B complex|Pfizer|6.25',
                'Shelcal|Shelcal 500 15 Tablets|Calcium + Vitamin D3|Torrent|5.90',
                'Zincovit|Zincovit 15 Tablets|Multivitamins + Zinc|Apex Laboratories|7.40',
            ]],
            'First Aid & Devices' => ['OTC', [
                'Dettol|Dettol Antiseptic Liquid 125ml|Chloroxylenol|Reckitt|3.50',
                'Savlon|Savlon Antiseptic Liquid 100ml|Chlorhexidine + Cetrimide|ITC|3.25',
                'Band-Aid|Band-Aid Washproof 20 Strips|Adhesive dressing|Johnson & Johnson|4.20',
                'Omron|Omron MC-246 Digital Thermometer|Digital thermometer|Omron|9.95',
                '3M|3M Surgical Face Mask 10 Pack|Three-layer face mask|3M|5.50',
            ]],
        ];

        $products = [];
        foreach ($groups as $category => [$schedule, $definitions]) {
            foreach ($definitions as $definition) {
                [$brand, $name, $generic, $manufacturer, $price] = explode('|', $definition);
                $products[] = compact('category', 'schedule', 'brand', 'name', 'generic', 'manufacturer', 'price');
            }
        }

        return $products;
    }
}
