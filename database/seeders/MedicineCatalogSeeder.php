<?php

namespace Database\Seeders;

use App\Models\MedicineCatalog;
use Illuminate\Database\Seeder;

/**
 * A starter reference set of well-known Bangladeshi pharmaceutical brands —
 * NOT an exhaustive market database (there are tens of thousands of
 * registered SKUs in Bangladesh; no scrape/license for that exists here).
 * This exists purely so a pharmacy owner adding a common medicine to their
 * shop can search-and-pick instead of typing the name/generic/company by
 * hand every time. The owner can always type an unlisted product manually —
 * this catalog is a convenience, never a restriction on what can be sold.
 */
class MedicineCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            // Paracetamol (pain/fever)
            ['name' => 'Napa', 'generic_name' => 'Paracetamol', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Napa Extra', 'generic_name' => 'Paracetamol + Caffeine', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg+65mg'],
            ['name' => 'Ace', 'generic_name' => 'Paracetamol', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Fast', 'generic_name' => 'Paracetamol', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Napa Syrup', 'generic_name' => 'Paracetamol', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Syrup', 'strength' => '120mg/5ml'],

            // Antacids / gastric (PPI)
            ['name' => 'Seclo', 'generic_name' => 'Omeprazole', 'company' => 'Square Pharmaceuticals', 'form' => 'Capsule', 'strength' => '20mg'],
            ['name' => 'Losectil', 'generic_name' => 'Omeprazole', 'company' => 'Incepta Pharmaceuticals', 'form' => 'Capsule', 'strength' => '20mg'],
            ['name' => 'Maxpro', 'generic_name' => 'Esomeprazole', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '20mg'],
            ['name' => 'Sergel', 'generic_name' => 'Esomeprazole', 'company' => 'Healthcare Pharmaceuticals', 'form' => 'Capsule', 'strength' => '20mg'],
            ['name' => 'Opton', 'generic_name' => 'Omeprazole', 'company' => 'ACI Limited', 'form' => 'Capsule', 'strength' => '20mg'],
            ['name' => 'Rani', 'generic_name' => 'Ranitidine', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '150mg'],

            // Antihistamines (allergy)
            ['name' => 'Fexo', 'generic_name' => 'Fexofenadine', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '120mg'],
            ['name' => 'Alatrol', 'generic_name' => 'Cetirizine', 'company' => 'ACI Limited', 'form' => 'Tablet', 'strength' => '10mg'],
            ['name' => 'Rin', 'generic_name' => 'Cetirizine', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '10mg'],
            ['name' => 'Monas', 'generic_name' => 'Montelukast', 'company' => 'Beacon Pharmaceuticals', 'form' => 'Tablet', 'strength' => '10mg'],
            ['name' => 'Montene', 'generic_name' => 'Montelukast', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '10mg'],

            // Antibiotics
            ['name' => 'Amdoxyl', 'generic_name' => 'Amoxicillin', 'company' => 'ACI Limited', 'form' => 'Capsule', 'strength' => '500mg'],
            ['name' => 'Moxacil', 'generic_name' => 'Amoxicillin', 'company' => 'Square Pharmaceuticals', 'form' => 'Capsule', 'strength' => '500mg'],
            ['name' => 'Ciprocin', 'generic_name' => 'Ciprofloxacin', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Azithrocin', 'generic_name' => 'Azithromycin', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Zimax', 'generic_name' => 'Azithromycin', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Flagyl', 'generic_name' => 'Metronidazole', 'company' => 'Renata Limited', 'form' => 'Tablet', 'strength' => '400mg'],
            ['name' => 'Fimoxyl', 'generic_name' => 'Amoxicillin', 'company' => 'Renata Limited', 'form' => 'Capsule', 'strength' => '500mg'],

            // Diabetes
            ['name' => 'Comet', 'generic_name' => 'Metformin', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Glucophage', 'generic_name' => 'Metformin', 'company' => 'Merck', 'form' => 'Tablet', 'strength' => '500mg'],
            ['name' => 'Amaryl', 'generic_name' => 'Glimepiride', 'company' => 'Sanofi', 'form' => 'Tablet', 'strength' => '2mg'],

            // Blood pressure / heart
            ['name' => 'Losartan', 'generic_name' => 'Losartan Potassium', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '50mg'],
            ['name' => 'Amdocal', 'generic_name' => 'Amlodipine', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '5mg'],
            ['name' => 'Norvasc', 'generic_name' => 'Amlodipine', 'company' => 'Pfizer', 'form' => 'Tablet', 'strength' => '5mg'],
            ['name' => 'Concor', 'generic_name' => 'Bisoprolol', 'company' => 'Merck', 'form' => 'Tablet', 'strength' => '5mg'],

            // Vitamins / supplements
            ['name' => 'Filwel', 'generic_name' => 'Folic Acid + Ferrous Fumarate', 'company' => 'Renata Limited', 'form' => 'Tablet', 'strength' => null],
            ['name' => 'Zinc-B', 'generic_name' => 'Zinc Sulphate', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Syrup', 'strength' => '20mg/5ml'],
            ['name' => 'Calbo-D', 'generic_name' => 'Calcium + Vitamin D3', 'company' => 'Aristopharma', 'form' => 'Tablet', 'strength' => null],
            ['name' => 'ORSaline', 'generic_name' => 'Oral Rehydration Salt', 'company' => 'ACME Laboratories', 'form' => 'Powder', 'strength' => null],
            ['name' => 'Neuro-B', 'generic_name' => 'Vitamin B1+B6+B12', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => null],

            // Anti-emetic / digestive
            ['name' => 'Domet', 'generic_name' => 'Domperidone', 'company' => 'Beximco Pharmaceuticals', 'form' => 'Tablet', 'strength' => '10mg'],
            ['name' => 'Motigut', 'generic_name' => 'Domperidone', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '10mg'],

            // Respiratory
            ['name' => 'Salbux', 'generic_name' => 'Salbutamol', 'company' => 'Square Pharmaceuticals', 'form' => 'Inhaler', 'strength' => '100mcg'],
            ['name' => 'Ventolin', 'generic_name' => 'Salbutamol', 'company' => 'GlaxoSmithKline', 'form' => 'Inhaler', 'strength' => '100mcg'],

            // Pain (NSAID)
            ['name' => 'Exilok', 'generic_name' => 'Esomeprazole', 'company' => 'Incepta Pharmaceuticals', 'form' => 'Capsule', 'strength' => '20mg'],
            ['name' => 'Ketorolac', 'generic_name' => 'Ketorolac', 'company' => 'Square Pharmaceuticals', 'form' => 'Tablet', 'strength' => '10mg'],
            ['name' => 'Indomet', 'generic_name' => 'Indomethacin', 'company' => 'ACI Limited', 'form' => 'Capsule', 'strength' => '25mg'],
            ['name' => 'Tornac', 'generic_name' => 'Aceclofenac', 'company' => 'Aristopharma', 'form' => 'Tablet', 'strength' => '100mg'],
        ];

        foreach ($entries as $entry) {
            MedicineCatalog::updateOrCreate(
                ['name' => $entry['name'], 'generic_name' => $entry['generic_name'], 'company' => $entry['company']],
                $entry
            );
        }
    }
}
