<?php

namespace App\Support;

/**
 * The starting Funds Allocation template: the monthly "FUNDS ALLOCATION IN PROGRESS" sheet as it is kept on paper (02.10.2026), line for
 * line. Every row is [description, beneficiary, request, allocation, TT, cash, USD]. Totals are not stored: the template page and the
 * generated sheet work them out from the rows.
 */
class FundAllocationTemplateSheet
{
    public static function default(): array
    {
        $i = fn (string $d, ?string $b = null, float $req = 0, float $alloc = 0, float $tt = 0, float $cash = 0, float $usd = 0) => [
            'description' => $d, 'beneficiary' => $b, 'contact_id' => null, 'beneficiary_user_id' => null, 'beneficiary_warehouse_id' => null,
            'request' => $req, 'allocation' => $alloc, 'tt' => $tt, 'cash' => $cash, 'usd' => $usd, 'extra_lines' => [], 'warehouse_id' => null, 'notes' => null,
        ];

        return [
            'title' => 'FUNDS ALLOCATION IN PROGRESS',
            'date' => '2026-10-02',
            'usd_rate' => 2470,
            'note' => 'PEMBA TT 320,000',
            'balances' => [
                ['label' => 'CASH BALANCE TZS', 'fund_account_id' => null, 'currency' => 'TZS', 'amount' => 49555000, 'exchange_rate' => 1],
                ['label' => 'CASH BALANCE USD($18,700)', 'fund_account_id' => null, 'currency' => 'USD', 'amount' => 18700, 'exchange_rate' => 2470],
                ['label' => 'BANK TZS BALANCE-8000', 'fund_account_id' => null, 'currency' => 'TZS', 'amount' => 0, 'exchange_rate' => 1],
                ['label' => 'BANK TZS BALANCE-8001', 'fund_account_id' => null, 'currency' => 'TZS', 'amount' => 0, 'exchange_rate' => 1],
                ['label' => 'BANK TZS BALANCE-7000', 'fund_account_id' => null, 'currency' => 'TZS', 'amount' => 57000000, 'exchange_rate' => 1],
                ['label' => 'BANK USD BALANCE-4000', 'fund_account_id' => null, 'currency' => 'USD', 'amount' => 0, 'exchange_rate' => 2470],
            ],
            'sections' => [
                ['category' => 'ADMINISTRATION', 'items' => [
                    $i('ADMIN UNGUJA', 'SALIM HAROUB', 6199000, 6199000, 0, 6199000),
                    $i('ADMIN PEMBA', 'TT-PBA ACC', 320000, 320000, 320000),
                    $i('MBWENI SHUWENA ZECO ELECTRIC BILL', 'MASOUD JUMA', 13430000),
                    $i('ZSSF JULY 2026 Contribution', 'ZSSF'),
                    $i('SOCIAL COPERATE RESPONSIBILITY (ZANZIBAR SPICE BOYS BRAZIL TRIP)', 'BALOZI ABDULSAMAD', 5400000, 5400000, 5400000),
                    $i('ZANZIBAR OUTREACH PROGRAM (ZOP) Annual contribution', 'ZOP', 1500000, 1500000, 1500000),
                    $i('DIESAL FOR GENERATOR', 'PREMIER', 1444480, 1444480, 0, 1444480),
                    $i('Salary Unguja JUNIOR JULY 2026, SITE & MASJID AUG 2026', 'ARAFA', 38647000),
                    $i('Salary Pemba JULY 2026', 'TT-PBA ACC', 3353500),
                    $i('SDL AUG', 'TRA', 411690),
                    $i('PAYEE AUG', 'ARAFA', 440854),
                ]],
                ['category' => 'PROCUREMENT', 'items' => [
                    $i('PROC-PETTY TRANSPORT UNGUJA', 'SALIM HAROUB', 10640000, 5605000, 0, 5605000),
                    $i('Proc-Petty transport Pemba', 'TT-PBA ACC'),
                    $i('TRAVELLING PEMBA', 'SUBRA'),
                    $i('MPENDAE HEALTH CENTRE HOSPITAL SIGNAGE', 'SAID RASHID', 8000000),
                ]],
                ['category' => 'CASH PROCUREMENT', 'items' => [
                    $i('SAID MASOUD-SEVERALS', 'SAID MASOUD TT', 67453200),
                    $i('ANIL-SEVERALS', 'ANIL-CASH', 16711700),
                    $i('LELA', 'LELA-CASH', 12629350),
                    $i('NASSIR A.SULTAN', 'NASSIR TT', 8930000),
                    $i('NANJIM-MARINE', 'NANJIM', 11330000),
                    $i('MASJID JAMII ZINJIBAR MONTHLY CLEANINING ITEMS JULY', 'RAMLA ISSA'),
                    $i('AISHA SULTAN RAK', 'AISHA SULTAN'),
                    $i('Mr NASSOR RESIDENCE K/SAMAKI- ROPE - 1 ROLL', 'KHAMIS UMEME'),
                    $i('PREMIER TOWER - FABRICATION OF SPYGOT(VINGANISHI)', 'SALIM ALI OMAR'),
                ]],
                ['category' => 'GENERAL LABOUR', 'items' => [
                    $i('MOMBASA ZSSF', 'ARAFA'),
                    $i('MPENDAE', 'ARAFA', 4076200, 2000000, 0, 2000000),
                    $i('K/CHEKUNDU', 'ARAFA', 220000, 220000, 0, 220000),
                    $i('PREMEIR TOWER', 'ARAFA', 5660000, 2000000, 0, 2000000),
                    $i('MBWENI SHUWENA', 'ARAFA', 1360000, 1360000, 0, 1360000),
                    $i('PSYCHIATRIC PEMBA', 'TT-PBA ACC'),
                    $i('MAINATENANCE PUBLIC - JAAMIU ZINJIBAR', 'ARAFA', 361000, 361000, 0, 361000),
                    $i('KOMBENI RANS HQ', 'ARAFA'),
                    $i('BALOZI CHUKWANI', 'ARAFA', 5000000, 5000000, 0, 5000000),
                    $i('BALOZI AL-SINAWI BUYU', 'ARAFA', 6571600, 3500000, 0, 3500000),
                    $i('MASJID ITIQAMA TUNGUU', 'ARAFA', 1735000, 1735000, 0, 1735000),
                    $i('MWERA-STAFF HOUSE', 'ARAFA'),
                    $i('MAALIM HAROUN RESIDENTIAL - MBWENI', 'ARAFA', 9369000, 3500000, 0, 3500000),
                    $i('KARAMA RESIDENCE KISAUNI', 'ARAFA', 9776072, 6000000, 0, 6000000),
                    $i('KWAMTIPURA SECONDARY SCHOOL', 'ARAFA', 5428600, 2000000, 0, 2000000),
                    $i('KISAKASAKA ZEC', 'ARAFA', 7490000, 2000000, 0, 2000000),
                ]],
                ['category' => 'LABOUR CONTRACTED WORKS', 'items' => [
                    $i('MR.MASOUD JUMA UMEME', 'ARAFA', 1250000, 1250000, 0, 1250000),
                    $i('FUNDI HAFIDH BOMBA', 'ARAFA', 5450000, 3000000, 0, 3000000),
                    $i('FUNDI SAID + HAMAD BOMBA', 'ARAFA'),
                    $i('FUNDI MAJIDI RANGI', 'ARAFA'),
                    $i('FUNDI HATIBU RANGI', 'ARAFA'),
                    $i('FUNDI MAULID RANGI', 'ARAFA'),
                    $i('FUNDI OMAR CORNICE', 'ARAFA'),
                    $i('SALIM A OMAR -SCAFOLDING', 'ARAFA', 76500, 76500, 0, 76500),
                    $i('KHAMIS GRILL WORKS', 'KHAMIS'),
                    $i('SURVEYORS', 'MR.JOROGE'),
                ]],
                ['category' => 'SUB-CONTRACTED WORKS', 'items' => [
                    $i('MWALIMU KHAMIS TERRAZO', null, 417326240),
                    $i('BORA', null, 43540000),
                    $i('ARAS', null, 391650000),
                    $i('YASIN AND SONS', null, 31400000),
                    $i('HITESH', null, 233328777),
                    $i('EDDY KV', null, 5150000),
                    $i('PRINCE INTERNATIONAL METAL WORKS', null, 170360000),
                    $i('KHAMIS GRILLS', null, 7228900),
                    $i('GOPI ENTERPRISES', null, 59448000),
                    $i('ELECTROTHERMAL', null, 3100000),
                    $i('YUSRA METAL WORKS', null, 40000000),
                    $i('HOME FURNITURE-SAID RASHID', 'SAID TT', 13958000),
                    $i('OZTI EA CO LTD US$ @ 2,470', null, 31616000),
                    $i('DERM ELEVATOR', null, 39848030),
                    $i('FIRST PILLING', null, 205091231),
                    $i('AC TECHNOLOGY', null, 20770550),
                    $i('EXTRION', null, 53357500),
                    $i('ELECTROGAS AND LABS LTD', null, 251257710),
                ]],
                ['category' => 'SITE SECURITY', 'items' => [
                    $i('2TECH AUG', 'TT', 6320000),
                    $i('ZNZ MODIFYING AUG', 'CASH', 2800000),
                ]],
                ['category' => 'PRIVATE', 'items' => [
                    $i('ALI NASSOR MBWENI', 'ARAFA'),
                    $i('MR NASSOR PLOT K/SAMAKI', 'ARAFA'),
                    $i('CHUKWANI FAHMI', 'ARAFA'),
                    $i('ORANGE BUILDING', 'ARAFA'),
                    $i('MR.HUMOUD CHUKWANI', 'ARAFA', 70000, 70000, 0, 70000),
                    $i('MR.NASSOR K.SAMAKI', 'ARAFA', 3727000, 727000, 0, 727000),
                ]],
                ['category' => 'SUPPLIERS', 'items' => [
                    $i('SUB SAHARA ALL', null, 1233652500),
                    $i('SALEH ALL', null, 66430000),
                    $i('ZINAMOLA', null, 140922500),
                    $i('ALHADAD', null, 166682000),
                    $i('NANJIM', null, 190300435),
                    $i('MASINGINI', null, 16665000),
                    $i('EVERGREEN', null, 201317350),
                    $i('SAID SEIF ALI', null, 48600000),
                    $i('OMAR HAMAD PEMBA', null, 4020000),
                    $i('MOHD AMOUR ABDALLAH', null, 347220000),
                    $i('MOHD KHAMIS', null, 25000000),
                    $i('MKUBWA BILAL', null, 2135100),
                    $i('MCL', null, 168913985),
                    $i('KIBOKO', null, 178056497),
                    $i('AFRIZANIA', null, 700),
                    $i('PATEL', null, 52423700),
                    $i('HOKOKAI', null, 31698800),
                    $i('MANSOUR NASSOR', null, 77725000),
                    $i('SHAKIR', null, 29617000),
                    $i('SAZUNA', null, 107125000),
                    $i('ENG.MBAROUK MASOUD', null, 16640000),
                    $i('RFB-DODOMA ELECTRICAL', null, 58217769),
                    $i('RFB-LIFT SUBCONTRACTOR', null, 51348530),
                    $i('KIGOROFANI HARDWARE STORE', null, 41038000),
                ]],
                ['category' => 'DIRECTOR ALLOWANCE', 'items' => [
                    $i('MR.NASSOR MISKIRY', null, 1000000, 1000000, 0, 1000000),
                    $i('MR.ALI NASSOR MISKIRY', null, 2000000, 2000000, 0, 2000000),
                    $i('MR.YUNUS A.GAARE'),
                    $i('ASHA NASSOR MISKIRY'),
                ]],
            ],
        ];
    }
}
