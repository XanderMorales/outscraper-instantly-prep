<?php

/* ===============================
   DEV MODE
   true  = include competitor columns in output (for debugging/analysis)
   false = clean output for Instantly (no competitor columns)
================================ */
$DEV_MODE = false;


/* ===============================
   TRIGGER MESSAGE TEMPLATES
================================ */
$is_test = false; // /tru or false - true to debug easily.
$test_trigger = false;
if($is_test) {
    $test_trigger = "Debug: You ({rating} stars) with {reviews} reviews... Competitor: ({comp_rating} stars) with {comp_reviews} reviews.\n\n";
}

# version a: (kick in the balls approach)
#$trigger_giant_slayer = $test_trigger . "{rating} stars and {reviews} reviews in {city}... that's a strong reputation.\n\nBut {competitor_name} keeps showing up above you with {comp_reviews} reviews.\n\nRight now their phone rings first.";
#$trigger_reputation_gap = $test_trigger . "Searched \"{category}\" in {city} this morning.\n\n{competitor_name} is showing up above you... {competitor_reviews} reviews to your {reviews}.\n\nMost people call the first name they see.";
#$trigger_reputation_issue = $test_trigger . "Searched \"{category}\" in {city} this morning.\n\nYour company came up... ({rating} stars).\n\n{competitor_name} ({comp_rating} stars) is showing above you.\n\nMost people call the name they trust.";
#$trigger_deserve_better = $test_trigger . "Searched \"{category}\" in {city} this morning.\n\nYour company came up... ({rating} stars).\n\n{competitor_name} ({comp_rating} stars) is showing above you.\n\nThat position difference is costing you calls.";
#$trigger_fallback = $test_trigger . "Searched \"{category}\" in {city} this morning.\n\nA few companies keep showing up consistently.\n\nDidn't see {name} showing up near the top results.";

# version b: (intriguing approach)
$trigger_giant_slayer = $test_trigger . "{rating} stars and {reviews} reviews in {city}... that's a solid reputation.\n\nI also see {competitor_name} shows up with {comp_reviews} reviews and others pulling up in local search results too.\n\nBut... I found a few things that could mean more phone calls for {name}.";
$trigger_reputation_gap = $test_trigger . "Searched \"{category}\" in {city} today.\n\nYour {reviews} reviews are holding strong… {competitor_name} is pushing hard with {competitor_reviews} reviews and they're not the only one pulling up in local search results.\n\nBut... I found a few things that could mean more phone calls for {name}.";
$trigger_reputation_issue = $test_trigger . "Searched \"{category}\" in {city} today.\n\nYour business came up, so did {competitor_name} with {comp_rating} stars and others are pulling up in local search results too.\n\nBut... I found a few things that could mean more phone calls for {name}.";
$trigger_deserve_better = $test_trigger . "Searched \"{category}\" in {city} today.\n\nYour business came up, so did {competitor_name} with {comp_rating} stars and others are pulling up in local search results too.\n\nBut... I found a few things that could mean more phone calls for {name}.";
#$trigger_fallback = $test_trigger . "Searched \"{category}\" in {city} today.\n\nA few other companies are showing up consistently in local search results too.\n\nBut... I found a few things that could mean more phone calls for {name}.";
$trigger_fallback = $test_trigger . "Searched \"{category}\" in {city} today. A few other companies are showing up consistently in local search results, but {name} isn't always part of that top group.";
/* ===============================
   OUTPUT COLUMN DEFINITIONS
================================ */
$output_columns = [
    "place_id"                      => "place_id",
    "batch_id"                      => null,
    "scrape_date"                   => null,
    "business_name"                 => "name_for_emails",
    "category"                      => "category",
    "latitude"                      => "latitude",
    "longitude"                     => "longitude",
    "address"                       => "address",
    "city"                          => "city",
    "state"                         => "state",
    "state_code"                    => "state_code",
    "postal_code"                   => "postal_code",
    "time_zone"                     => "time_zone",
    "domain"                        => "domain",
    "is_franchise_domain"           => null,
    "email_domain_matches_business" => null,
    "website"                       => "website",
    "phone"                         => "phone",
    "rating"                        => "rating",
    "reviews"                       => "reviews",
    "photos_count"                  => "photos_count",
    "review_tier"                   => null,
    "business_status"               => "business_status",
    "verified"                      => "verified",
    "google_id"                     => "google_id",
    "source"                        => "source",
    "company_linkedin"              => "company_linkedin",
    "company_facebook"              => "company_facebook",
    "company_instagram"             => "company_instagram",
    "company_x"                     => "company_x",
    "company_youtube"               => "company_youtube",
    "website_title"                 => "website_title",
    "website_description"           => "website_description",
    "website_generator"             => "website_generator",
    "website_has_gtm"               => "website_has_gtm",
    "website_has_fb_pixel"          => "website_has_fb_pixel",
    "full_name"                     => "full_name",
    "first_name"                    => "first_name",
    "last_name"                     => "last_name",
    "title"                         => "title",
    "email"                         => "email",
    "email_type"                    => null,
    "title_type"                    => null,
    "competitor_name"               => null,
    "competitor_rating"             => null,
    "competitor_reviews"            => null,
    "competitor_tier"               => null,
    "total_competitors"             => null,
    "trigger_message"               => null,
    "trigger_type"                  => null,
];


/* ===============================
   SIMPLIFIED COLUMNS
   Used for contacts.csv and companies.csv (Instantly upload)
================================ */
$simplified_columns = [
    "place_id", "batch_id", "scrape_date", "companyName", "firstName", "lastName",
    "category", "address", "city", "state", "website", "phone", "email",
    "trigger_message", "trigger_type", "rating", "reviews",
];

if ($DEV_MODE) {
    $simplified_columns = array_merge($simplified_columns, [
        "competitor_name", "competitor_rating", "competitor_reviews",
        "competitor_tier", "total_competitors",
    ]);
}
/*
$simplified_columns = [
    "place_id","batch_id","scrape_date","companyName","firstName","lastName",
    "category","address","city","state","website","phone","email",
    "trigger_message","trigger_type","rating","reviews","competitor_name","competitor_rating",
    "competitor_reviews","competitor_tier","total_competitors",
];
*/

/* ===============================
    REVIEW TIER DEFINITIONS
    Ghost       0–30
    Contender   31–120
    Favorite    121–350
    King        351–800
    Legend      801+
================================ */
$TIER_ORDER = [
    "Ghost"      => 0,
    "Contender"  => 1,
    "Favorite"   => 2,
    "King"       => 3,
    "Franchise"  => 4,
];

$franchise_domains = [
    // ===== Dealership / Enterprise Domains =====
    "lexusofmanhattan.com",
    "cadillacofmanhattan.com",
    "simpletire.com",
    "coachusa.com",
    "bosch.com",
    "web.com",
    "koreaportal.com",
    "latofonts.com",
    "giantpanda.com",

    // Auto Body & Collision
    "caliber.com",
    "gerbercollision.com",
    "maaco.com",
    "carstar.com",
    "fixauto.com",
    "cbac.com",

    // Auto Repair & Maintenance
    "meineke.com",
    "precisiontune.com",
    "jiffylube.com",
    "midas.com",
    "tuffy.com",
    "christianbrothersauto.com",
    "take5oilchange.com",
    "bigotires.com",
    "greasemonkeyauto.com",
    "honest1.com",
    "aamco.com",
    "speedeeoil.com",
    "autolabusa.com",
    "stricklandbrothers.com",
    "tirepros.com",
    "hotshotsecret.com",
    "monrobrakes.com",
    "valvoline.com",
    "firestone.com",
    "goodyear.com",
    "pepboys.com",
    "expressoil.com",
    "ntb.com",

    // Transmission Repair
    "cottman.com",
    "mrtransmission.com",
    "leemusauto.com",

    // HVAC
    "onehourheatandair.com",
    "aireserv.com",
    "serviceexperts.com",
    "temperaturepro.com",
    "callhero.com",
    "cooltoday.com",
    "heatandairgurus.com",
    "righttimegroup.com",
    "aireflo.com",

    // Plumbing
    "mrrooter.com",
    "benfranklinplumbing.com",
    "rotorooter.com",
    "zoomdrain.com",
    "rooterhero.com",
    "thepinkplumber.com",
    "drainmedic.com",
    "1tomplumber.com",
    "redcapplumbing.com",
    "mikediamondservices.com",
    "thegentlemanplumber.com",

    // Electrical
    "mistersparky.com",
    "mrelectric.com",
    "safeelectric.com",
    "electricianfranchise.com",

    // Roofing
    "stormguardrc.com",
    "roofmaxx.com",
    "mightydogroofing.com",
    "bestchoiceroofing.com",
    "westernstatesroofing.com",

    // Restoration & Disaster Services
    "servpro.com",
    "servicemasterrestore.com",
    "paulbdavis.com",
    "rainbowrestores.com",
    "puroclean.com",
    "restoration1.com",
    "steamatic.com",
    "911restoration.com",

    // Lawn Care & Turf Management
    "trugreen.com",
    "weedmanusa.com",
    "spring-green.com",
    "uslawns.com",
    "groundsguys.com",

    // Tree & Arborist Services
    "bartlett.com",
    "davey.com",

    // Landscape & Hardscape
    "grasshopperlandscaping.com",
];

$franchise_names = [
    // ================= DEALERSHIP GROUPS =================
    "Lexus of ",
    "Toyota of ",
    "Honda of ",
    "Ford of ",
    "Nissan of ",
    "BMW of ",
    "Chevrolet of ",
    "Hyundai of ",
    "Kia of ",
    "Subaru of ",
    "Mercedes-Benz of ",
    "Volkswagen of ",
    "Audi of ",
    "Jeep of ",
    "Dodge of ",
    "Chrysler of ",
    "GMC of ",
    "Cadillac of ",

    // ================= DEALERSHIP GROUP KEYWORDS =================
    "Auto Group",
    "Motor Group",
    "Automotive Group",
    "Auto Mall",
    "Motors Group",
    "Dealer Group",
    "Automotive Holdings",
    "Automotive Management",
    "Collision Group",
    "Auto Holdings",
    " MINI of ",
    "MINI of ",
    "AutoMall",
    "Automall",
    "Dealership",
    "Certified Pre-Owned",

    // =====================================================
    // AUTO BODY & COLLISION
    // =====================================================
    "Caliber Collision",
    "Gerber Collision & Glass",
    "Maaco",
    "CARSTAR",
    "Fix Auto USA",
    "Service King Collision Repair",
    "Abra Auto Body Repair",
    "Classic Collision",
    "Crash Champions",
    "ProCare Collision",
    "Joe Hudson's Collision Center",

    // =====================================================
    // AUTO REPAIR & MAINTENANCE
    // =====================================================
    "Walmart Auto Care Center",
    "Midas",
    "Meineke Car Care Centers",
    "Pep Boys",
    "Christian Brothers Automotive",
    "Precision Tune Auto Care",
    "Honest-1 Auto Care",
    "Tuffy Tire & Auto Service",
    "Monro Auto Service and Tire Centers",
    "AAMCO Transmissions & Total Car Care",
    "Grease Monkey",
    "SpeeDee Oil Change & Auto Service",
    "Take 5 Oil Change",
    "Valvoline Instant Oil Change",
    "Jiffy Lube",
    "Strickland Brothers 10 Minute Oil Change",
    "Express Oil Change & Tire Engineers",
    "Big O Tires",
    "NTB Tire & Service Centers",
    "Tire Kingdom",
    "Les Schwab Tire Centers",
    "Belle Tire",
    "Discount Tire",
    "Tire Pros",
    "Firestone Complete Auto Care",
    "Goodyear Auto Service",

    // =====================================================
    // TRANSMISSION REPAIR
    // =====================================================
    "Cottman Transmission and Total Auto Care",
    "Mr. Transmission",
    "Lee Myles Transmissions",

    // =====================================================
    // HVAC
    // =====================================================
    "One Hour Heating & Air Conditioning",
    "Aire Serv",
    "Service Experts Heating & Air Conditioning",
    "TemperaturePro",
    "CallHero",
    "CoolToday",
    "Right Time Heating and Air Conditioning",
    "Aire-Master",
    "Air Pros USA",
    "Comfort Experts",
    "ARCO Comfort Air",

    // =====================================================
    // PLUMBING
    // =====================================================
    "Mr. Rooter Plumbing",
    "Benjamin Franklin Plumbing",
    "Roto-Rooter Plumbing & Water Cleanup",
    "Zoom Drain",
    "Rooter Hero Plumbing",
    "The Pink Plumber",
    "Drain Medic",
    "1 Tom Plumber",
    "Red Cap Plumbing & Air",
    "Mike Diamond Services",
    "The Gentlemen Plumbers",
    "Bluefrog Plumbing + Drain",
    "Superior Plumbing",
    "Mr. Rescue Plumbing & HVAC",

    // =====================================================
    // ELECTRICAL
    // =====================================================
    "Mr. Electric",
    "Mister Sparky",
    "Safe Electric",
    "Electrician USA",
    "Mister Voltage",
    "24 Hour Electric",
    "WireNut Home Services",

    // =====================================================
    // ROOFING
    // =====================================================
    "Storm Guard Roofing and Construction",
    "Roof Maxx",
    "Mighty Dog Roofing",
    "Best Choice Roofing",
    "Bone Dry Roofing",
    "Great Roofing & Restoration",
    "Power Home Remodeling",
    "CentiMark Roofing",
    "Dream Home Roofing",

    // =====================================================
    // RESTORATION & DISASTER SERVICES
    // =====================================================
    "SERVPRO",
    "ServiceMaster Restore",
    "Paul Davis Restoration",
    "Rainbow Restoration",
    "PuroClean",
    "Restoration 1",
    "Steamatic",
    "911 Restoration",
    "United Water Restoration Group",
    "First Onsite Property Restoration",

    // =====================================================
    // LAWN CARE & TURF MANAGEMENT
    // =====================================================
    "TruGreen",
    "Weed Man",
    "Spring-Green Lawn Care",
    "U.S. Lawns",
    "The Grounds Guys",
    "Lawn Doctor",
    "Nutri-Lawn",
    "Lawn Pride",
    "Heroes Lawn Care",

    // =====================================================
    // TREE & ARBORIST SERVICES
    // =====================================================
    "The Davey Tree Expert Company",
    "Bartlett Tree Experts",
    "Monster Tree Service",
    "TreeNewal",
    "Arbor Masters",

    // =====================================================
    // LANDSCAPE & HARDSCAPE
    // =====================================================
    "Grasshopper Landscaping",
    "Landscape Creations",
    "System Pavers",
    "Archadeck Outdoor Living",
    "Outdoor Lighting Perspectives",
    "Conserva Irrigation",
    "Green Home Solutions"
];

$free_email_domains = [
    "gmail.com","yahoo.com","hotmail.com",
    "outlook.com","icloud.com","aol.com",
];

$disposable_domains = [
    "mailinator.com","tempmail.com",
    "10minutemail.com","guerrillamail.com",
    "yopmail.com",
];

$owner_keywords = [
    "owner","founder","president","ceo","cfo","cmo","principal","proprietor",
    "managing member","partner","business development manager",
    "director of information technology","vice","chair","chairman","vp","v.p.",
    "officer","license holder","qualifying party","master plumber",
    "master electrician","master technician","general manager","operations manager",
    "director","service manager","service director","sales and marketing manager",
    "collision center manager","body shop manager","shop manager","shop foreman",
    "office manager","business manager","controller",
];

$generic_prefixes = [
    "info","sales","support","admin","contact","office","hello","help",
    "billing","team","care","customerservice","service",
];
