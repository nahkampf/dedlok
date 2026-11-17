<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Kronos Capital (docs/BANK.md)
    |--------------------------------------------------------------------------
    |
    | The entity whose Exchange desk carries the bank's counter in cyberspace.
    | That desk offers the bank and nothing else: Kronos sets no missions.
    |
    | EVERY NUMBER BELOW IS A PLACEHOLDER until the loans are played.
    */

    'entity' => 'Kronos Capital',

    // The most a loan can be at each standing with Kronos: [standing, creds], read in between as a straight line. Below
    // standing 0 there is no loan at all, and standing 100 is the top.
    'limit' => [[0, 3000], [1, 6000], [5, 12000], [10, 35000], [25, 70000], [50, 125000], [75, 225000], [100, 400000]],

    // The smallest loan. Below it the whole-cred rounding lets interest outrun the instalment.
    'min_loan' => 100,

    // Simple interest on the principal still owed, charged every night whether or not the borrower logs in.
    // Must stay under the instalment's daily share (payment_percent over payment_hours / 24): a test asserts it.
    'interest_daily' => 0.005,

    // The instalment: a share of the amount BORROWED, every so many hours, rounded up.
    'payment_percent' => 4,
    'payment_hours' => 48,

    // Paying the whole loan early: the principal, the interest so far, and this share of the amount borrowed.
    'payoff_fee_percent' => 2,

    // From a missed instalment to the default.
    'grace_hours' => 24,

    'standing' => [
        // Standing with Kronos after a default.
        'default' => -5,
        // Per night, from the balance held: points per 1 000 creds, and the most it pays in a day.
        'balance' => ['per_thousand' => 0.02, 'cap' => 0.6],
        // From interest paid (the payoff fee counts): points per cred, and the most in a day.
        'interest' => ['per_credit' => 0.01, 'cap' => 0.5],
    ],

    // The share of every wallet inflow taken while a settlement runs.
    'settlement_share' => 50,

    // A refusal puts this share of what is owed on the borrower's head.
    'bounty_percent' => 10,

    'seizure' => [
        // The first night's chance, in percent, and what each night after adds.
        'start' => 10,
        'step' => 5,
        // A safehouse counts for this many days of its rent; stored items for this share of their base cost.
        'rent_days' => 8,
        'items_percent' => 75,
    ],
];
