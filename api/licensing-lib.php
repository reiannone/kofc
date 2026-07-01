<?php
/**
 * licensing-lib.php
 * ------------------------------------------------------------------
 * Structured, state-keyed licensing lookup for the KofC advisor.
 *
 * Purpose: for questions that name a U.S. jurisdiction, resolve the state and
 * inject AUTHORITATIVE licensing facts straight into the model context, instead
 * of relying on fuzzy vector retrieval for data that must be exactly right.
 *
 * Pairs with the "Licensing & Regulations" KB collection, which carries the
 * narrative "why"; this lib carries the per-state "what".
 *
 * Data source of truth: table licensing_state_requirements (see licensing-schema.sql).
 * Verify-only fields are NULL in the table and render here as a DOI hand-off.
 * ------------------------------------------------------------------
 */

// Facts identical across every jurisdiction (kept out of the table to avoid 51x duplication).
const KOFC_LICENSING_BASELINE =
      "Knights of Columbus is a fraternal benefit society selling FIXED insurance and annuity "
    . "products only (term/whole/universal life, fixed annuities, long-term care, disability income). "
    . "The baseline credential in every U.S. state is a Life & Health insurance producer license plus "
    . "a KofC carrier appointment. No variable products are offered, so NO securities registration "
    . "(FINRA Series 6/7) is required.";

const KOFC_PR20_URL =
      "https://content.naic.org/sites/default/files/model-law-chart-pr-20-producer-education-and-examination-requirements.pdf";

/**
 * Canonical name/abbreviation -> two-letter code map.
 * @return array<string,string>
 */
function kofc_state_map() {
    return array(
        "alabama"=>"AL","alaska"=>"AK","arizona"=>"AZ","arkansas"=>"AR","california"=>"CA",
        "colorado"=>"CO","connecticut"=>"CT","delaware"=>"DE","district of columbia"=>"DC",
        "washington dc"=>"DC","washington d.c."=>"DC","florida"=>"FL","georgia"=>"GA","hawaii"=>"HI",
        "idaho"=>"ID","illinois"=>"IL","indiana"=>"IN","iowa"=>"IA","kansas"=>"KS","kentucky"=>"KY",
        "louisiana"=>"LA","maine"=>"ME","maryland"=>"MD","massachusetts"=>"MA","michigan"=>"MI",
        "minnesota"=>"MN","mississippi"=>"MS","missouri"=>"MO","montana"=>"MT","nebraska"=>"NE",
        "nevada"=>"NV","new hampshire"=>"NH","new jersey"=>"NJ","new mexico"=>"NM","new york"=>"NY",
        "north carolina"=>"NC","north dakota"=>"ND","ohio"=>"OH","oklahoma"=>"OK","oregon"=>"OR",
        "pennsylvania"=>"PA","rhode island"=>"RI","south carolina"=>"SC","south dakota"=>"SD",
        "tennessee"=>"TN","texas"=>"TX","utah"=>"UT","vermont"=>"VT","virginia"=>"VA",
        "washington"=>"WA","west virginia"=>"WV","wisconsin"=>"WI","wyoming"=>"WY",
    );
}

/**
 * Detect a single state referenced in free text and return its 2-letter code, or null.
 *
 * Matching rules (ordered to avoid false positives):
 *   1. Full names, longest first, so "West Virginia" is not swallowed by "Virginia"
 *      and "Washington DC" beats "Washington".
 *   2. Uppercase 2-letter codes on a word boundary (e.g. "TX", "NY"). Lowercase tokens
 *      are ignored on purpose so common words ("in", "or", "me", "hi") do not match.
 *
 * If two different states are named, returns null (ambiguous) so the caller can fall
 * back to normal retrieval rather than assert the wrong state.
 *
 * @param string $text
 * @return string|null  two-letter code
 */
function kofc_licensing_detect_state($text) {
    if ($text === null || $text === "") {
        return null;
    }
    $map = kofc_state_map();
    $found = array();

    // 1. Full-name matches (case-insensitive, whole word), longest name first.
    $names = array_keys($map);
    usort($names, function($a, $b) { return strlen($b) - strlen($a); });
    $lower = " " . strtolower($text) . " ";
    foreach ($names as $name) {
        $pattern = "/\\b" . preg_quote($name, "/") . "\\b/";
        if (preg_match($pattern, $lower)) {
            $found[$map[$name]] = true;
        }
    }

    // 2. Uppercase abbreviation matches on the ORIGINAL-case text.
    $codes = array_values(array_unique(array_values($map)));
    foreach ($codes as $code) {
        if (preg_match("/\\b" . $code . "\\b/", $text)) { // case-sensitive: only uppercase
            $found[$code] = true;
        }
    }

    if (count($found) === 1) {
        return array_key_first($found);
    }
    return null; // none, or ambiguous
}

/**
 * Fetch the row for a state code.
 * @param PDO $pdo
 * @param string $stateCode
 * @return array|null
 */
function kofc_licensing_lookup(PDO $pdo, $stateCode) {
    $stmt = $pdo->prepare(
        "SELECT state_code, state_name, regulator, doi_url, annuity_bi_adopted, "
      . "annuity_bi_note, annuity_training, prelicensing_hours, ltc_training, ce_cycle "
      . "FROM licensing_state_requirements WHERE state_code = :code LIMIT 1"
    );
    $stmt->execute(array(":code" => $stateCode));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Render one field as either its confirmed value or a verify hand-off.
 */
function kofc_licensing_field($value, $doiUrl, $extra = "") {
    $value = ($value === null) ? "" : trim($value);
    if ($value === "") {
        $tail = ($extra !== "") ? " " . $extra : "";
        return "NOT CONFIRMED - do not state a figure; hand off to " . $doiUrl . $tail;
    }
    return $value;
}

/**
 * Build the authoritative context block + a citation descriptor.
 *
 * @param array $row  a row from kofc_licensing_lookup()
 * @return array{text:string, citation:array}
 */
function kofc_licensing_context_block(array $row) {
    $doi   = $row["doi_url"];
    $state = $row["state_name"];

    switch ($row["annuity_bi_adopted"]) {
        case "yes":     $bi = "Adopted the revised (2020) NAIC best-interest annuity model (#275)."; break;
        case "no":      $bi = "Has NOT adopted the revised NAIC model."; break;
        case "pending": $bi = "Adoption of the revised NAIC model is PENDING."; break;
        default:        $bi = "Adoption status NOT CONFIRMED - verify at " . $doi . "."; break;
    }
    if (!empty($row["annuity_bi_note"])) {
        $bi .= " " . $row["annuity_bi_note"];
    }

    $lines = array();
    $lines[] = "[AUTHORITATIVE LICENSING FACTS - " . $state . "]";
    $lines[] = "Trust these over any conflicting general text below. Do not invent figures.";
    $lines[] = "Any item marked NOT CONFIRMED must be handed off to the linked source, never stated as fact.";
    $lines[] = "- Jurisdiction: " . $state . " (" . $row["state_code"] . ")";
    $lines[] = "- Regulator: " . $row["regulator"];
    $lines[] = "- Department of Insurance (binding source): " . $doi;
    $lines[] = "- Baseline credential: Life & Health insurance producer license + KofC carrier appointment.";
    $lines[] = "- Securities registration (FINRA Series 6/7): Not required; KofC sells fixed products only.";
    $lines[] = "- Annuity best-interest model: " . $bi;
    $lines[] = "- Annuity initial training: " . kofc_licensing_field($row["annuity_training"], $doi);
    $lines[] = "- Prelicensing education (Life/Health): " . kofc_licensing_field($row["prelicensing_hours"], $doi, "or the NAIC PR-20 chart (" . KOFC_PR20_URL . ").");
    $lines[] = "- Long-term care (LTC) training: " . kofc_licensing_field($row["ltc_training"], $doi);
    $lines[] = "- Continuing education (CE) cycle: " . kofc_licensing_field($row["ce_cycle"], $doi, "or the NAIC PR-20 chart (" . KOFC_PR20_URL . ").");
    $lines[] = "- Binding source to cite for " . $state . ": " . $doi;

    $text = implode("\n", $lines);

    $citation = array(
        "title"      => $state . " - " . $row["regulator"],
        "url"        => $doi,
        "collection" => "Licensing & Regulations",
        "origin"     => "structured",   // distinguishes from vector-retrieved chunks
    );

    return array("text" => $text, "citation" => $citation);
}

/**
 * Convenience: text + citation for a query, or null if no single state is detected.
 *
 * @param PDO $pdo
 * @param string $query  the (already refined) user query
 * @return array{text:string, citation:array}|null
 */
function kofc_licensing_for_query(PDO $pdo, $query) {
    $code = kofc_licensing_detect_state($query);
    if ($code === null) {
        return null;
    }
    $row = kofc_licensing_lookup($pdo, $code);
    if ($row === null) {
        return null;
    }
    return kofc_licensing_context_block($row);
}
