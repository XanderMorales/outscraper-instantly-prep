<?php
/**
 * parse.php — Outscraper Lead Processing Pipeline
 * 
 * BRIEF
 * --------
 * We get data using Outscraper.cloud website - one city search and one industry at a time.
 * Once we do that multiple times we donload all the files into the $input_folder.
 * Then you execute this script and it will create two CSV files (contacts & companies) in the $output_folder.
 * After that, we upload those files into MillionVerifier to clean the email data.
 * The next steps are in prep_instantly.php...
 *
 * How to execute script at command line "php parse.php"
 *
 * OVERVIEW
 * --------
 * This script takes raw CSV exports from Outscraper.cloud (one file per city/industry
 * combination) and transforms them into clean, enriched lead files ready for outreach
 * via Instantly.ai (or similar cold email platforms).
 *
 * INPUTS
 * ------
 * - One or more Outscraper CSV files placed in: data/1 - scraped/
 * - Each file represents a single city + industry search (e.g. "auto repair shop in Tampa FL")
 * - config.php must be present in the same directory (column definitions, templates, lists)
 *
 * WHAT IT DOES
 * ------------
 * PASS 1 — VALIDATION & DEDUPLICATION
 *   Reads every row from every input file and removes records that are:
 *   - Missing a place_id or email address
 *   - Invalid email format
 *   - Duplicate email (globally across all input files)
 *   - From a disposable email domain (mailinator, tempmail, etc.)
 *   - From a .gov or .edu domain
 *   - Missing a business website/domain
 *   - Optionally: failing an MX DNS check ($ENABLE_MX_CHECK)
 *   Rejected records are written to a separate bad_records CSV with a removal_reason column.
 *
 *   NOTE ON place_id DEDUPLICATION:
 *   Outscraper returns one row per contact per business. A single business may have
 *   multiple contacts (and thus multiple rows with the same place_id but different emails).
 *   We intentionally allow ALL valid contacts through — deduplication is by EMAIL only,
 *   not by place_id. The place_id is used only to build the competitor geo index (once
 *   per business). This ensures we capture every valid contact Outscraper found.
 *
 * PASS 2 — ENRICHMENT & COMPETITOR ANALYSIS
 *   For each valid business, the script:
 *
 *   1. EXPANDING RADIUS COMPETITOR SEARCH
 *      Starting at 1 mile and expanding out to $COMPETITOR_MAX_MILES (default: 10),
 *      the script finds up to $COMPETITOR_MAX_COUNT (default: 25) nearby competitors
 *      in the same category. Franchises are excluded. Competitors are collected
 *      closest-first within each 1-mile band, so the search stops early as soon
 *      as the list is full — avoiding unnecessary distance calculations.
 *      Each competitor's name, distance, rating, and review count is stored as
 *      comp1_name / comp1_distance / comp1_rating / comp1_reviews ... comp25_*
 *
 *   2. TRIGGER COMPETITOR SELECTION
 *      From the competitor list, the script picks the single most relevant competitor
 *      to reference in the outreach message. It filters to businesses in the same or
 *      higher review tier, then selects the closest one geographically — because the
 *      nearest credible threat is the most compelling talking point.
 *
 *   3. REVIEW TIER CLASSIFICATION
 *      Every business (and its chosen competitor) is assigned a tier based on
 *      Google review count:
 *        Ghost:      0–30 reviews
 *        Contender:  31–120 reviews
 *        Favorite:   121–350 reviews
 *        King:       351–800 reviews
 *        Franchise:  801+ reviews
 *
 *   4. TRIGGER MESSAGE GENERATION
 *      A personalized cold email opening line is generated based on the business's
 *      rating, review count, city, category, and chosen competitor. Templates used:
 *        - deserve_better:     solid rating but being outranked nearby
 *        - reputation_gap:     low review count, competitor has more
 *        - reputation_issue:   low rating, competitor has better stars
 *        - giant_slayer:       very high rating (4.8+) but still being beaten on visibility
 *        - fallback:           no qualifying competitor found nearby
 *
 *   5. EMAIL & CONTACT CLASSIFICATION
 *      Each email is tagged by type (domain_match / free / generic) and each contact
 *      title is tagged as owner_level or non_owner based on keyword matching.
 *      Franchise businesses are separated into their own output file.
 *
 * OUTPUTS (written to data/2 - parsed/)
 * --------------------------------------
 *   {timestamp}_all_data.csv     — All valid independent businesses, fully enriched
 *   {timestamp}_contacts.csv     — Subset with a first name (person-level outreach)
 *   {timestamp}_companies.csv    — Subset without a first name (company-level outreach)
 *   {timestamp}_franchises.csv   — Franchise businesses (separated, not used for outreach)
 *   {timestamp}_bad_records.csv  — Rejected rows with removal reason and source file
 *
 * NEXT STEPS
 * ----------
 * After running this script:
 *   1. Upload contacts.csv and companies.csv to MillionVerifier for email validation
 *   2. Use the cleaned files as input for prep_instantly.php to format for Instantly.ai
 *
 * USAGE
 * -----
 *   php parse.php
 */

/* ===============================
   CONFIG
================================ */
$input_folder  = 'data/1 - scraped';   // Directory containing all Outscraper CSVs
$output_folder = 'data/2 - parsed';    // Output directory

// Output filenames (consolidated across all input files)
$f_time_stamp = date('ymdHs');
$output_filename    = "new_" . $f_time_stamp . "_all_data.csv";
$contacts_filename  = "new_" . $f_time_stamp . "_contacts.csv";
$companies_filename = "new_" . $f_time_stamp . "_companies.csv";
$bad_filename       = "new_" . $f_time_stamp . "_bad_records.csv";
$franchise_filename = "new_" . $f_time_stamp . "_franchises.csv";

$scrape_date = date("M j, Y");  // e.g., "Feb 14, 2026"

$ENABLE_MX_CHECK      = false;
$COMPETITOR_MAX_COUNT = 25;
$COMPETITOR_MAX_MILES = 10;

require __DIR__ . '/config.php';

/* ===============================
   Extract batch_id from filename
   e.g. "Outscraper-20260225170343s95_auto_repair_shop_+1.csv"
        → "20260225170343s95"
================================ */
function extract_batch_id(string $filename): string {
    $base = basename($filename, '.csv');
    if (preg_match('/Outscraper-([^_]+)/i', $base, $m)) {
        return $m[1];
    }
    return $base;
}

function getReviewTier($reviews) {
    $reviews = (int)$reviews;
    if ($reviews <= 30)  return "Ghost";
    if ($reviews <= 120) return "Contender";
    if ($reviews <= 350) return "Favorite";
    if ($reviews <= 800) return "King";
    return "Franchise";
}

/* ===============================
   HELPERS
================================ */
function normalize($v)           { return strtolower(trim((string)$v)); }
function field($row, $map, $key) { return (isset($map[$key]) && isset($row[$map[$key]])) ? $row[$map[$key]] : ""; }
function email_domain($email)    { return strpos($email, "@") !== false ? substr(strrchr($email, "@"), 1) : ""; }
function email_prefix($email)    { return strpos($email, "@") !== false ? substr($email, 0, strpos($email, "@")) : ""; }
function has_mx($domain)         { return checkdnsrr($domain, 'MX'); }

function is_owner_title($title, $keywords) {
    $title = strtolower(trim((string)$title));
    foreach ($keywords as $k) {
        if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $title)) return true;
    }
    return false;
}

function is_franchise_name($businessName, $franchiseNames) {
    $businessName = strtolower(trim((string)$businessName));
    foreach ($franchiseNames as $name) {
        if (stripos($businessName, strtolower(trim($name))) !== false) return true;
    }
    return false;
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/* ===============================
   CLEAN BUSINESS NAME
   Safe, deterministic rules only — no casing guesses.
   1. Remove anything after a slash (with or without spaces around it)
   2. Remove anything after ' - ' (space-dash-space), preserving hyphenated words
   3. Remove parentheses/curly braces and their contents
   4. Remove trailing business entity suffixes (LLC, Inc, Corp, etc.)
   5. Trim trailing punctuation and whitespace
================================ */
function clean_business_name(string $name): string {
    // 1. Slash — split on / or \ with optional surrounding spaces
    $name = preg_split('/\s*[\/\\\\]\s*/', $name)[0];

    // 2. Dash — only strip on ' - ' (space-dash-space), NOT hyphenated words
    $name = preg_split('/\s+-\s+/', $name)[0];

    // 3. Remove parentheses and curly braces and their contents
    $name = preg_replace('/[\(\{][^\)\}]*[\)\}]/', '', $name);

    // 4. Remove business entity suffixes (with or without leading comma)
    $name = preg_replace('/,?\s*(LLC\.?|Inc\.?|Corp\.?|Co\.?|Ltd\.?|LLP\.?|PC\.?|PLC\.?|PA\.)$/i', '', $name);

    // 5. Trim trailing punctuation and whitespace
    $name = rtrim(trim($name), ' ,.');

    return $name;
}

/* ===============================
   GEO INDEX — spatial bucket for fast competitor lookup
   Groups businesses into 0.1° grid cells (~6.9 miles each).
   Only the 9 surrounding cells are scanned per business,
   reducing O(n²) distance checks to near O(n).
================================ */
function buildGeoIndex(array $rows, $map, float $cellSize = 0.1): array {
    $index = [];
    foreach ($rows as $row) {
        $lat = (float)field($row, $map, "latitude");
        $lon = (float)field($row, $map, "longitude");
        if (!$lat || !$lon) continue;
        $key = floor($lat / $cellSize) . ":" . floor($lon / $cellSize);
        $index[$key][] = $row;
    }
    return $index;
}

function getNearbyFromIndex(float $lat, float $lon, array $geoIndex, float $cellSize = 0.1): array {
    $latKey  = floor($lat / $cellSize);
    $lonKey  = floor($lon / $cellSize);
    $results = [];
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            $key = ($latKey + $dx) . ":" . ($lonKey + $dy);
            if (isset($geoIndex[$key])) {
                $results = array_merge($results, $geoIndex[$key]);
            }
        }
    }
    return $results;
}

/* ===============================
   EXPANDING RADIUS COMPETITOR SEARCH
   Walks out 1 mile at a time, collecting non-franchise competitors
   sorted by distance within each band. Stops once $maxCount is reached
   or $maxMiles is exhausted.

   Returns array of up to $maxCount entries:
   [
     'name'     => string,
     'distance' => float (miles),
     'rating'   => float,
     'reviews'  => int,
     'tier'     => string,
   ]
================================ */
function findCompetitorsByExpandingRadius(
    $currentBusiness,
    $allBusinesses,
    $map,
    int $maxMiles,
    int $maxCount
): array {
    $currentName     = clean_business_name(field($currentBusiness, $map, "name"));
    $currentLat      = (float)field($currentBusiness, $map, "latitude");
    $currentLon      = (float)field($currentBusiness, $map, "longitude");
    $currentCategory = strtolower(trim(field($currentBusiness, $map, "category")));
    if ($currentCategory === "") $currentCategory = strtolower(trim(field($currentBusiness, $map, "type")));

    $collected      = [];  // Final ordered list, closest first
    $collectedNames = []; // Keyed lookup to avoid duplicates efficiently

    for ($radius = 1; $radius <= $maxMiles; $radius++) {
        $band = [];  // Competitors found in this 1-mile band

        $nearbyPool = isset($GLOBALS['geoIndex'])
            ? getNearbyFromIndex($currentLat, $currentLon, $GLOBALS['geoIndex'])
            : $allBusinesses;

        foreach ($nearbyPool as $competitor) {
            $compName = clean_business_name(field($competitor, $map, "name"));
            if ($compName === $currentName) continue;

            // Skip franchises
            $compDomain = normalize(field($competitor, $map, "domain"));
            if (
                in_array($compDomain, $GLOBALS['franchise_domains'], true) ||
                is_franchise_name($compName, $GLOBALS['franchise_names'])
            ) continue;

            // Must be in the same category
            $compCategory = strtolower(trim(field($competitor, $map, "category")));
            if ($compCategory === "") $compCategory = strtolower(trim(field($competitor, $map, "type")));
            if ($currentCategory !== $compCategory) continue;

            $compLat  = (float)field($competitor, $map, "latitude");
            $compLon  = (float)field($competitor, $map, "longitude");
            $dist     = calculateDistance($currentLat, $currentLon, $compLat, $compLon);

            // Only consider competitors in THIS mile band
            $prevRadius = $radius - 1;
            if ($dist <= $prevRadius || $dist > $radius) continue;

            // Skip if already collected
            if (isset($collectedNames[$compName])) continue;

            $compReviews = (int)field($competitor, $map, "reviews");
            $compRating  = (float)field($competitor, $map, "rating");

            // Skip businesses with missing or zero rating/reviews
            if ($compReviews <= 0 || $compRating <= 0.0) continue;

            $compTier = getReviewTier($compReviews);

            // Exclude franchise-tier businesses
            if ($compTier === "Franchise") continue;

            $band[] = [
                'name'     => $compName,
                'distance' => $dist,
                'rating'   => $compRating,
                'reviews'  => $compReviews,
                'tier'     => $compTier,
            ];
        }

        // Sort this band by reviews descending, then distance ascending as tiebreaker
        usort($band, function($a, $b) {
            if ($b['reviews'] !== $a['reviews']) return $b['reviews'] <=> $a['reviews'];
            return $a['distance'] <=> $b['distance'];
        });

        // Collect from this band until we hit our limit
        $done = false;
        foreach ($band as $entry) {
            $collected[]                    = $entry;
            $collectedNames[$entry['name']] = true;
            if (count($collected) >= $maxCount) { $done = true; break; }
        }
        if ($done) break;
    }

    return $collected;
}

/* ===============================
   DETERMINE TRIGGER TYPE
================================ */
function determineTriggerType(float $rating, int $reviews): string {
    if ($rating === 0.0)                   return 'fallback';
    if ($rating < 4.2)                     return 'reputation_issue';
    if ($rating >= 4.8 && $reviews >= 120) return 'giant_slayer';
    if ($rating >= 4.2 && $reviews >= 30)  return 'reputation_gap';
    return 'deserve_better';
}

/* ===============================
   PICK BEST COMPETITOR FOR TRIGGER MESSAGE
================================ */
function findTieredCompetitorFromList(array $competitorList, int $currentReviews, float $currentRating, string $triggerType): ?array {
    $bestCandidate = null;
    $bestScore     = PHP_INT_MAX;

    foreach ($competitorList as $comp) {
        if ((int)$comp['reviews'] <= 0 || (float)$comp['rating'] <= 0.0) continue;

        if ($triggerType === 'giant_slayer') {
            if ($comp['rating'] < ($currentRating - 0.5)) continue;
            if ($comp['reviews'] < ($currentReviews * 0.8)) continue;
        }

        if ($triggerType === 'reputation_gap') {
            if ($comp['reviews'] <= $currentReviews) continue;
        }

        if ($triggerType === 'deserve_better') {
            if ($comp['reviews'] <= $currentReviews) continue;
            if ($comp['rating'] < ($currentRating - 0.3)) continue;
        }

        $reviewRatio = $comp['reviews'] / max($currentReviews, 1);
        $ratingDelta = abs($comp['rating'] - $currentRating);
        $distance    = $comp['distance'] ?? 0;
        $score       = abs(log($reviewRatio)) * 2 + ($ratingDelta * 3) + ($distance * 0.1);

        if ($score < $bestScore) {
            $bestScore     = $score;
            $bestCandidate = $comp;
        }
    }

    return $bestCandidate;
}

/* ===============================
   COUNT TIERED COMPETITORS from pre-built list
================================ */
function countTieredCompetitorsFromList(array $competitorList, int $currentReviews): int {
    $currentTierNum = $GLOBALS['TIER_ORDER'][getReviewTier($currentReviews)];
    $count = 0;
    foreach ($competitorList as $comp) {
        if ($comp['tier'] === "Franchise") continue;
        $compTierNum = $GLOBALS['TIER_ORDER'][$comp['tier']];
        if ($compTierNum < $currentTierNum) continue;
        $count++;
    }
    return $count;
}

/* ===============================
   GENERATE TRIGGER MESSAGE
================================ */
function generateTriggerMessage($businessName, $rating, $reviews, $city, $category, $competitorName, $compRating, $compReviews, $templates, $triggerType) {
    $reviews  = (int)$reviews;
    $template = $templates[$triggerType] ?? $templates['fallback'];

    $replace = [
        '{name}'     => $businessName,
        '{rating}'   => $rating,
        '{reviews}'  => $reviews,
        '{city}'     => $city,
        '{category}' => $category,
    ];

    if ($triggerType !== 'fallback') {
        $replace['{competitor_name}']    = $competitorName;
        $replace['{comp_rating}']        = $compRating;
        $replace['{competitor_rating}']  = $compRating;
        $replace['{comp_reviews}']       = $compReviews;
        $replace['{competitor_reviews}'] = $compReviews;
    }

    $message = str_replace(array_keys($replace), array_values($replace), $template);

    return [$message, $triggerType];
}

/* ===============================
   BUILD DYNAMIC COMPETITOR COLUMN HEADERS
================================ */
function buildCompetitorHeaders(int $maxCount): array {
    $headers = [];
    for ($i = 1; $i <= $maxCount; $i++) {
        $headers[] = "comp{$i}_name";
        $headers[] = "comp{$i}_distance";
        $headers[] = "comp{$i}_rating";
        $headers[] = "comp{$i}_reviews";
    }
    return $headers;
}

function buildCompetitorRow(array $competitorList, int $maxCount): array {
    $cells = [];
    for ($i = 0; $i < $maxCount; $i++) {
        if (isset($competitorList[$i])) {
            $c = $competitorList[$i];
            $cells[] = $c['name'];
            $cells[] = round($c['distance'], 2);
            $cells[] = $c['rating'];
            $cells[] = $c['reviews'];
        } else {
            $cells[] = "";
            $cells[] = "";
            $cells[] = "";
            $cells[] = "";
        }
    }
    return $cells;
}

/* ===============================
   SETUP OUTPUT FILES
================================ */
if (!is_dir($output_folder)) mkdir($output_folder, 0777, true);

$comp_headers            = buildCompetitorHeaders($COMPETITOR_MAX_COUNT);
$output_headers          = array_keys($output_columns);
$output_headers_full     = array_merge($output_headers, $comp_headers);
$simplified_columns_full = $DEV_MODE
    ? array_merge($simplified_columns, $comp_headers)
    : $simplified_columns;

$out           = fopen($output_folder . "/" . $output_filename,    "w");
$franchise_out = fopen($output_folder . "/" . $franchise_filename, "w");
$contacts_out  = fopen($output_folder . "/" . $contacts_filename,  "w");
$companies_out = fopen($output_folder . "/" . $companies_filename, "w");
$bad           = fopen($output_folder . "/" . $bad_filename,       "w");

fputcsv($out,           $output_headers_full,     ",", '"', "\\");
fputcsv($franchise_out, $output_headers_full,     ",", '"', "\\");
fputcsv($contacts_out,  $simplified_columns_full, ",", '"', "\\");
fputcsv($companies_out, $simplified_columns_full, ",", '"', "\\");

$bad_headers_written = false;

/* ===============================
   DISCOVER INPUT FILES
================================ */
$csv_files = glob($input_folder . "/*.csv");
if (empty($csv_files)) {
    die("No CSV files found in: $input_folder\n");
}

sort($csv_files);
echo "Found " . count($csv_files) . " file(s) to process.\n\n";

/* ===============================
   GLOBAL COUNTERS & TRACKING
================================ */
$seenEmails   = [];  // Deduplicates by email — primary dedup mechanism
$seenPlaceIds = [];  // Tracks which place_ids are already in the geo/competitor pool
$totals = [
    "read"       => 0,
    "removed"    => 0,
    "kept"       => 0,
    "franchises" => 0,
    "contacts"   => 0,
    "companies"  => 0,
];

/* ===============================
   PROCESS EACH FILE
================================ */
foreach ($csv_files as $input_csv) {

    $batch_id = extract_batch_id($input_csv);
    echo "Processing: " . basename($input_csv) . "  (batch_id: $batch_id)\n";

    $in = fopen($input_csv, "r");
    if (!$in) { echo "  ⚠ Could not open file, skipping.\n"; continue; }

    $headers = fgetcsv($in, 0, ",", '"', "\\");
    if (!$headers) { echo "  ⚠ Invalid CSV, skipping.\n"; fclose($in); continue; }

    if (!$bad_headers_written) {
        $bad_row_headers   = $headers;
        $bad_row_headers[] = "removal_reason";
        $bad_row_headers[] = "source_file";
        fputcsv($bad, $bad_row_headers, ",", '"', "\\");
        $bad_headers_written = true;
    }

    $map = [];
    foreach ($headers as $i => $h) { $map[trim($h)] = $i; }

    $file_counts = ["read" => 0, "removed" => 0, "valid" => 0];
    $validRows   = [];
    $allRows     = [];  // Competitor pool — one entry per unique place_id with valid rating+reviews

    /* ===== FIRST PASS: VALIDATION ===== */
    while (($row = fgetcsv($in, 0, ",", '"', "\\")) !== false) {

        $file_counts["read"]++;
        $totals["read"]++;

        // ── place_id required ──
        $place_id = field($row, $map, "place_id");
        if (!$place_id) {
            $row[] = "missing_place_id"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        // ── Build competitor pool: one entry per place_id ──
        // Multiple contacts from the same business all pass through for outreach,
        // but the geo index only needs one representative row per business.
        $poolRating  = (float)field($row, $map, "rating");
        $poolReviews = (int)field($row, $map, "reviews");
        if (!isset($seenPlaceIds[$place_id]) && $poolRating > 0.0 && $poolReviews > 0) {
            $allRows[] = $row;
            $seenPlaceIds[$place_id] = true;
        }

        // ── Email required ──
        $email = normalize(field($row, $map, "email"));
        if (!$email) {
            $row[] = "missing_email"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        // ── Valid email format ──
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row[] = "invalid_email"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        // ── No duplicate emails ──
        if (isset($seenEmails[$email])) {
            $row[] = "duplicate_email"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }
        $seenEmails[$email] = true;

        $emailDomain = email_domain($email);

        // ── No disposable domains ──
        if (in_array($emailDomain, $GLOBALS['disposable_domains'], true)) {
            $row[] = "disposable_domain"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        // ── No .gov or .edu ──
        if (preg_match('/\.(gov|edu)$/i', $emailDomain)) {
            $row[] = "blocked_gov_edu"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        // ── Optional MX check ──
        if ($ENABLE_MX_CHECK && $emailDomain && !has_mx($emailDomain)) {
            $row[] = "mx_failed"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        // ── Website required ──
        $domain_check = normalize(field($row, $map, "domain"));
        if (empty($domain_check)) {
            $row[] = "no_website"; $row[] = basename($input_csv);
            fputcsv($bad, $row, ",", '"', "\\");
            $file_counts["removed"]++; $totals["removed"]++; continue;
        }

        $validRows[] = $row;
        $file_counts["valid"]++;
    }

    fclose($in);

    /* ===== BUILD GEO INDEX (once per file, before competitor search) ===== */
    $GLOBALS['geoIndex'] = buildGeoIndex($allRows, $map);

    /* ===== SECOND PASS: COMPETITOR ANALYSIS & OUTPUT ===== */
    foreach ($validRows as $row) {

        $domain             = normalize(field($row, $map, "domain"));
        $emailDomain        = email_domain(field($row, $map, "email"));
        $emailDomainMatches = ($domain && $emailDomain === $domain) ? "TRUE" : "FALSE";
        $businessName       = clean_business_name(field($row, $map, "name"));

        $isFranchise = (
            in_array($domain, $franchise_domains, true) ||
            is_franchise_name($businessName, $franchise_names)
        ) ? "TRUE" : "FALSE";

        $is_generic = in_array(email_prefix(field($row, $map, "email")), $generic_prefixes, true);
        $is_free    = in_array($emailDomain, $free_email_domains, true);

        $email_type = "domain_match";
        if ($is_free)    $email_type = "free";
        if ($is_generic) $email_type = "generic";

        $title      = field($row, $map, "title");
        $title_type = is_owner_title($title, $owner_keywords) ? "owner_level" : "non_owner";

        $reviews    = (int)field($row, $map, "reviews");
        $reviewTier = getReviewTier($reviews);

        $rating   = (float)field($row, $map, "rating");
        $city     = field($row, $map, "city");
        $category = strtolower(trim(field($row, $map, "category")));
        if ($category === "") $category = strtolower(trim(field($row, $map, "type")));

        /* ===== EXPANDING RADIUS COMPETITOR SEARCH ===== */
        $competitorList = findCompetitorsByExpandingRadius(
            $row, $allRows, $map, $COMPETITOR_MAX_MILES, $COMPETITOR_MAX_COUNT
        );

        // 1. Determine potential trigger type
        $potentialTrigger = determineTriggerType($rating, $reviews);

        // 2. Find best competitor matching trigger criteria
        $topCandidate = findTieredCompetitorFromList($competitorList, $reviews, $rating, $potentialTrigger);

        // 3. Count qualifying competitors
        $totalCompetitors = 0;
        if ($topCandidate) {
            foreach ($competitorList as $comp) {
                $valid = true;
                if ($potentialTrigger === 'giant_slayer') {
                    if ($comp['rating'] < ($rating - 0.3))   $valid = false;
                    if ($comp['reviews'] < ($reviews * 0.8)) $valid = false;
                }
                if ($potentialTrigger === 'reputation_gap') {
                    if ($comp['reviews'] <= $reviews) $valid = false;
                }
                if ($potentialTrigger === 'deserve_better') {
                    if ($comp['reviews'] <= $reviews)       $valid = false;
                    if ($comp['rating'] < ($rating - 0.3)) $valid = false;
                }
                if ($potentialTrigger === 'reputation_issue') {
                    if ($comp['rating'] <= $rating) $valid = false;
                }
                if ($valid) $totalCompetitors++;
            }
        }

        $triggerTemplates = [
            'deserve_better'   => $trigger_deserve_better,
            'reputation_gap'   => $trigger_reputation_gap,
            'giant_slayer'     => $trigger_giant_slayer,
            'reputation_issue' => $trigger_reputation_issue,
            'fallback'         => $trigger_fallback,
        ];

        // 4. Assign trigger type and competitor data — or force clean fallback
        if ($topCandidate && $totalCompetitors > 0) {
            $triggerType    = $potentialTrigger;
            $competitorName = $topCandidate['name'];
            $compRating     = $topCandidate['rating'];
            $compReviews    = $topCandidate['reviews'];
            $compTier       = $topCandidate['tier'];
        } else {
            $triggerType      = "fallback";
            $competitorName   = "";
            $compRating       = "";
            $compReviews      = "";
            $compTier         = "";
            $totalCompetitors = 0;
        }

        // 5. Generate trigger message
        [$triggerMessage, $triggerType] = generateTriggerMessage(
            $businessName, $rating, $reviews, $city, $category,
            $competitorName, $compRating, $compReviews, $triggerTemplates, $triggerType
        );

        /* ===== BUILD OUTPUT ROW ===== */
        $clean = [];
        foreach ($output_columns as $output_name => $input_field) {
            if ($input_field === null) {
                switch ($output_name) {
                    case "is_franchise_domain":            $clean[] = $isFranchise;        break;
                    case "batch_id":                       $clean[] = $batch_id;            break;
                    case "scrape_date":                    $clean[] = $scrape_date;         break;
                    case "email_domain_matches_business":  $clean[] = $emailDomainMatches;  break;
                    case "email_type":                     $clean[] = $email_type;          break;
                    case "title_type":                     $clean[] = $title_type;          break;
                    case "review_tier":                    $clean[] = $reviewTier;          break;
                    case "competitor_name":                $clean[] = $competitorName;      break;
                    case "competitor_rating":              $clean[] = $compRating;          break;
                    case "competitor_reviews":             $clean[] = $compReviews;         break;
                    case "competitor_tier":                $clean[] = $compTier;            break;
                    case "total_competitors":              $clean[] = $totalCompetitors;    break;
                    case "trigger_message":                $clean[] = $triggerMessage;      break;
                    case "trigger_type":                   $clean[] = $triggerType;         break;
                    default:                               $clean[] = "";
                }
            } else {
                if ($output_name === "category") {
                    $val = trim(field($row, $map, $input_field));
                    if ($val === "") $val = trim(field($row, $map, "type"));
                    $clean[] = strtolower($val);
                } else {
                    $clean[] = field($row, $map, $input_field);
                }
            }
        }

        $comp_cells = buildCompetitorRow($competitorList, $COMPETITOR_MAX_COUNT);
        $clean      = array_merge($clean, $comp_cells);

        /* ===== ROUTE TO FILE ===== */
        if ($isFranchise === "TRUE") {
            fputcsv($franchise_out, $clean, ",", '"', "\\");
            $totals["franchises"]++;
        } else {
            fputcsv($out, $clean, ",", '"', "\\");
            $totals["kept"]++;
        }

        /* ===== CONTACTS & COMPANIES ===== */
        if ($isFranchise === "FALSE") {

            $firstName = trim(field($row, $map, "first_name"));
            $lastName  = trim(field($row, $map, "last_name"));
            $website   = field($row, $map, "website");
            $phone     = field($row, $map, "phone");
            $emailVal  = field($row, $map, "email");
            $address   = field($row, $map, "address");
            $state     = field($row, $map, "state");

            $simplified_row = [
                field($row, $map, "place_id"),
                $batch_id,
                $scrape_date,
                $businessName,
                $firstName,
                $lastName,
                $category,
                $address,
                $city,
                $state,
                $website,
                $phone,
                $emailVal,
                $triggerMessage,
                $triggerType,
                $rating,
                $reviews,
            ];

            if ($DEV_MODE) {
                $simplified_row = array_merge($simplified_row, [
                    $competitorName,
                    $compRating,
                    $compReviews,
                    $compTier,
                    $totalCompetitors,
                ]);
                $simplified_row = array_merge($simplified_row, $comp_cells);
            }

            if (!empty($firstName)) {
                fputcsv($contacts_out, $simplified_row, ",", '"', "\\");
                $totals["contacts"]++;
            } else {
                fputcsv($companies_out, $simplified_row, ",", '"', "\\");
                $totals["companies"]++;
            }
        }
    }

    echo "  Read: {$file_counts['read']}  |  Valid: {$file_counts['valid']}  |  Removed: {$file_counts['removed']}\n";
}

fclose($out);
fclose($franchise_out);
fclose($contacts_out);
fclose($companies_out);
fclose($bad);

/* ===============================
   SUMMARY
================================ */
echo "\n========================================\n";
echo "COMPLETE — ALL FILES PROCESSED\n";
echo "========================================\n";
echo "Total read:              " . $totals["read"]       . "\n";
echo "Kept (Independents):     " . $totals["kept"]       . "\n";
echo "Franchises:              " . $totals["franchises"] . "\n";
echo "Contacts (with name):    " . $totals["contacts"]   . "\n";
echo "Companies (no name):     " . $totals["companies"]  . "\n";
echo "Removed:                 " . $totals["removed"]    . "\n";
echo "\n";
echo "Output folder: $output_folder\n";
echo "  → " . $output_filename    . "\n";
echo "  → " . $franchise_filename . "\n";
echo "  → " . $contacts_filename  . "\n";
echo "  → " . $companies_filename . "\n";
echo "  → " . $bad_filename       . "\n";
