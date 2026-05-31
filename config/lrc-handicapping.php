<?php
/**
 * LRC Handicapping Configuration
 *
 * Tunable parameters for the handicapping pipeline.
 * No database schema changes — all config is application-level.
 */

return [
    // Number of historical events to collect per member for pace analysis
    'history_rows_default' => 8,

    // Distance window (±km) for selecting similar-distance historical events
    'distance_window' => 2.5,  // km

    // Standard distance for pace normalisation (5km default, matches legacy)
    'std_distance' => 5.0,  // km

    // Outlier threshold: remove paces > (threshold × stdDev) from mean
    'outlier_threshold' => 1.3,

    // DOB tolerances for fuzzy matching (days)
    'dob_month_tolerance' => 30,   // ≈ ±1 month
    'dob_year_tolerance' => 365,  // ≈ ±1 year

    // Minimum data points required for pace computations
    'min_history_for_avg' => 1,
    'min_history_for_lsf' => 3,
    'min_history_for_mlr' => 3,

    // Path within storage/app/ for handicapping working files
    'storage_path' => 'handicapping',

    // Supported prediction methods (for reference)
    'methods' => [
        'ave'   => 'Average Pace',
        'lsf'   => 'Least Squares Fit',
        'mlr'   => 'Machine Learning (Linear Regression)',
        'man'   => 'Manual Override',
    ],

    // Default prediction method used to pre-populate spreadsheet
    'default_method' => 'ave',

    // Divisions
    'divisions' => [
        1 => 'Long Course',
        2 => 'Short Course',
        3 => 'Junior',
    ],
];
