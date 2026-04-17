# outscraper-instantly-prep

A PHP utility script that transforms raw [Outscraper](https://outscraper.com) CSV exports into hyper-personalized cold email leads ready for [Instantly.ai](https://instantly.ai).

Built for local service business outreach — auto shops, HVAC, plumbing, roofing, landscaping, and more.

---

## What it does

You scrape one industry and one city/or state at a time in Outscraper. You end up with a folder full of CSVs. This script processes all of them and produces clean, enriched lead files with a personalized trigger message already built into each row — ready to drop into Instantly.

The trigger message references a real nearby competitor pulled from the same scrape data. No manual research. No generic openers.

---

## The flow

```
Outscraper → parse.php → MillionVerifier → Instantly
```

1. Run Outscraper searches (one industry + one city/or state per search)
2. Drop all CSV exports into `data/1 - scraped/`
3. Run `php parse.php`
4. Upload `contacts.csv` and `companies.csv` to MillionVerifier for email validation
5. Import cleaned files into Instantly and use `{{trigger_message}}` in your sequence

---

## Example trigger message output

```
Searched "auto repair shop" in Tampa today.

Your 47 reviews are holding strong... Mike's Auto Center is pushing hard
with 218 reviews and they're not the only one pulling up in local search results.

But... I found a few things that could mean more phone calls for Tampa Tire & Auto.
```

Each message is generated based on the business's actual rating, review count, city, category, and the nearest qualifying competitor found in the scrape data.

---

## Trigger types

| Type               | When it fires                                                              |
| ------------------ | -------------------------------------------------------------------------- |
| `giant_slayer`     | High rating (4.8+) and solid reviews, but a competitor still outranks them |
| `reputation_gap`   | Good rating but competitor has significantly more reviews                  |
| `reputation_issue` | Low rating, competitor has better stars                                    |
| `deserve_better`   | Solid rating but being outranked nearby                                    |
| `fallback`         | No qualifying competitor found — generic local search angle                |

---

## Outputs

All files are written to `data/2 - parsed/` with a timestamp prefix.

| File               | Contents                                              |
| ------------------ | ----------------------------------------------------- |
| `_all_data.csv`    | All valid independent businesses, fully enriched      |
| `_contacts.csv`    | Records with a first name (person-level outreach)     |
| `_companies.csv`   | Records without a first name (company-level outreach) |
| `_franchises.csv`  | Franchise businesses, separated out                   |
| `_bad_records.csv` | Rejected rows with removal reason                     |

---

## What gets filtered out

- Missing place_id or email
- Invalid email format
- Duplicate emails (globally across all input files)
- Disposable email domains
- `.gov` and `.edu` domains
- No business website
- Franchise businesses (separated, not used for outreach)
- Competitors with zero rating or zero reviews

---

## Competitor intelligence

For each business, the script runs an expanding radius search (1 mile at a time, up to 10 miles) against all other businesses in the scrape pool. It finds up to 25 nearby competitors in the same category, sorts them by review count within each mile band, and picks the single most relevant one to reference in the trigger message.

Franchises are excluded from competitor selection by domain and by name matching against a built-in list.

The geo index uses a 0.1° grid bucketing system to avoid O(n²) distance calculations across large scrape files.

---

## Review tiers

| Tier      | Review count |
| --------- | ------------ |
| Ghost     | 0–30         |
| Contender | 31–120       |
| Favorite  | 121–350      |
| King      | 351–800      |
| Franchise | 801+         |

---

## Setup

1. Clone the repo
2. Place your Outscraper CSV exports in `data/1 - scraped/`
3. Edit `config.php` to set your trigger message templates, franchise lists, and column mappings
4. Run:

```bash
php parse.php
```

Requires PHP 7.4+ with standard extensions. No Composer dependencies.

---

## Config

All configuration lives in `config.php`:

- Trigger message templates (one per trigger type)
- Franchise domain and name exclusion lists
- Output column definitions
- Owner-level title keywords
- Free and disposable email domain lists
- DEV_MODE toggle (adds competitor debug columns to output)

---

## Instantly sequence example

```
Subject: Quick question for you

{{Hi|Hey}} {{firstName}},

{{trigger_message}}

I have a 60-second snapshot that shows exactly where they're beating you
and if your website is helping or hurting your rankings.

Want me to send it over?

{{accountSignature}}
```

Map `trigger_message` from your CSV to the `{{trigger_message}}` variable in Instantly.

---

## Notes

- Scrape one industry and one city/state at a time in Outscraper for best results
- Run contacts and companies through MillionVerifier before importing to Instantly
- The script processes all CSVs in the input folder in a single run
- Multiple contacts from the same business all pass through — deduplication is by email, not by business
